<?php

namespace App\Domain\Compliance\Services;

use App\Domain\Compliance\Models\BeneficialOwner;
use App\Domain\Compliance\Models\ClientIdentification;
use App\Domain\Compliance\Notifications\IdentificationExpiring;
use App\Domain\Documents\Scanning\ScannerUnavailable;
use App\Domain\Documents\Scanning\VirusScanner;
use App\Domain\Matters\Enums\MatterStatus;
use App\Domain\Matters\Models\Client;
use App\Domain\Matters\Models\Firm;
use App\Domain\Matters\Models\Matter;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Knowing the client: their identification documents (with a scan), the
 * beneficial owners of a juridical client, a risk rating and whether they
 * are a politically exposed person, reviewed by a lawyer. IDs about to
 * expire are flagged to the lawyers on the client's open matters.
 *
 * What the firm must collect, and when, depends on the AMLA rules for
 * lawyers and the firm's own policy; this records it, it does not decide it.
 */
class KnowYourClient
{
    public const EXPIRY_WARNING_DAYS = 30;

    public function __construct(private readonly VirusScanner $scanner) {}

    /** @param array{id_type: string, id_number: string, issued_on?: ?string, expires_on?: ?string, notes?: ?string} $data */
    public function addIdentification(Client $client, array $data, ?UploadedFile $scan, User $by): ClientIdentification
    {
        $stored = $scan ? $this->store($client, $scan, $by) : [];

        $id = ClientIdentification::create([
            'firm_id' => $client->firm_id,
            'client_id' => $client->id,
            'id_type' => $data['id_type'],
            'id_number' => $data['id_number'],
            'issued_on' => $data['issued_on'] ?? null,
            'expires_on' => $data['expires_on'] ?? null,
            'notes' => $data['notes'] ?? null,
            'verified_by' => $by->id,
            'verified_at' => now(),
            ...$stored,
        ]);

        return $id;
    }

    public function removeIdentification(ClientIdentification $id): void
    {
        if ($id->path) {
            Storage::disk('local')->delete($id->path);
        }
        $id->delete();
    }

    /** @param array{risk: string, is_pep: bool, notes?: ?string} $data */
    public function review(Client $client, array $data, User $by): Client
    {
        $client->forceFill([
            'kyc_risk' => $data['risk'],
            'is_pep' => $data['is_pep'],
            'kyc_notes' => $data['notes'] ?? null,
            'kyc_reviewed_at' => now(),
            'kyc_reviewed_by' => $by->id,
        ])->save();
        AuditLog::record('kyc_reviewed', $client->firm_id, $by, $client, ['risk' => $data['risk'], 'is_pep' => $data['is_pep']]);

        return $client;
    }

    /**
     * What is missing or needs attention, in plain words.
     *
     * @return list<string>
     */
    public function problems(Client $client): array
    {
        $ids = ClientIdentification::query()->where('client_id', $client->id)->get();
        $valid = $ids->filter(fn (ClientIdentification $i) => ! $i->isExpired());
        $problems = [];

        if ($valid->isEmpty()) {
            $problems[] = $ids->isEmpty() ? 'No identification on file.' : 'Every identification on file has expired.';
        } elseif ($valid->every(fn (ClientIdentification $i) => $i->expires_on !== null && $i->expires_on->lte(today()->addDays(self::EXPIRY_WARNING_DAYS)))) {
            $problems[] = 'The identification on file expires within '.self::EXPIRY_WARNING_DAYS.' days.';
        }
        if ($client->type === 'corporate' && ! BeneficialOwner::query()->where('client_id', $client->id)->exists()) {
            $problems[] = 'Beneficial owners not recorded.';
        }
        if ($client->kyc_reviewed_at === null) {
            $problems[] = 'Not yet reviewed and risk-rated.';
        }

        return $problems;
    }

    /** Daily: IDs expiring within 30 days, and expired ones, for clients with open matters. Each stage once. */
    public function remindExpiring(?CarbonImmutable $today = null): int
    {
        $today ??= CarbonImmutable::today();
        $sent = 0;

        Firm::query()->pluck('id')->each(function (int $firmId) use ($today, &$sent) {
            app(TenantContext::class)->runAs($firmId, function () use ($today, &$sent) {
                ClientIdentification::query()
                    ->whereNotNull('expires_on')
                    ->where('expires_on', '<=', $today->addDays(self::EXPIRY_WARNING_DAYS)->toDateString())
                    ->with('client')
                    ->get()
                    ->each(function (ClientIdentification $id) use ($today, &$sent) {
                        $stage = $id->expires_on->lt($today) ? 'expired' : 'soon';
                        if ($id->reminded_stage === $stage || $id->reminded_stage === 'expired' || $id->client === null) {
                            return;
                        }
                        // A newer valid ID makes this one irrelevant.
                        $newer = ClientIdentification::query()->where('client_id', $id->client_id)->whereKeyNot($id->id)
                            ->where(fn ($q) => $q->whereNull('expires_on')->orWhere('expires_on', '>', $today->addDays(self::EXPIRY_WARNING_DAYS)->toDateString()))->exists();
                        $lawyers = Matter::query()->where('client_id', $id->client_id)->where('status', '!=', MatterStatus::Closed->value)->pluck('responsible_lawyer_id')->filter()->unique();
                        $id->forceFill(['reminded_stage' => $stage])->saveQuietly();
                        if ($newer || $lawyers->isEmpty()) {
                            return;
                        }
                        User::query()->where('is_active', true)
                            ->where(fn ($q) => $q->whereIn('id', $lawyers)->orWhere('role', Role::ManagingPartner->value))
                            ->get()
                            ->each->notify(new IdentificationExpiring($id, $id->client, $stage));
                        $sent++;
                    });
            });
        });

        return $sent;
    }

    /** @return array{path: string, original_name: string, mime_type: string, sha256: string} */
    private function store(Client $client, UploadedFile $scan, User $by): array
    {
        try {
            $result = $this->scanner->scan($scan->getRealPath());
        } catch (ScannerUnavailable $e) {
            report($e);
            if (! config('services.clamav.fail_open')) {
                abort(503, 'Files cannot be uploaded right now because the virus scanner is unavailable. Please try again in a few minutes.');
            }
            $result = null;
        }
        if ($result?->isInfected()) {
            AuditLog::record('file_rejected_malware', $client->firm_id, $by, $client, ['name' => $scan->getClientOriginalName(), 'signature' => $result->signature]);
            throw ValidationException::withMessages(['scan' => "This file contains malware ({$result->signature}) and was not saved."]);
        }

        $extension = strtolower($scan->guessExtension() ?: $scan->getClientOriginalExtension());
        $path = $scan->storeAs("firms/{$client->firm_id}/clients/{$client->id}/identification", Str::uuid()->toString().($extension ? ".{$extension}" : ''), 'local');
        if ($path === false) {
            abort(500, 'The file could not be stored. Please try again.');
        }

        return [
            'path' => $path,
            'original_name' => Str::limit($scan->getClientOriginalName(), 250, ''),
            'mime_type' => $scan->getMimeType() ?: 'application/octet-stream',
            'sha256' => hash_file('sha256', $scan->getRealPath()),
        ];
    }
}
