<?php

namespace App\Domain\Intake\Services;

use App\Domain\Compliance\Enums\ConflictCheckStatus;
use App\Domain\Compliance\Services\ConflictChecker;
use App\Domain\Intake\Models\IntakeRequest;
use App\Domain\Intake\Notifications\ConsultationScheduled;
use App\Domain\Intake\Notifications\IntakeDeclined;
use App\Domain\Intake\Notifications\IntakeReceived;
use App\Domain\Intake\Notifications\NewIntakeRequest;
use App\Domain\Matters\Actions\OpenMatter;
use App\Domain\Matters\Enums\PartyRole;
use App\Domain\Matters\Models\Client;
use App\Domain\Matters\Models\Firm;
use App\Domain\Matters\Models\Matter;
use App\Domain\Prescription\Prescriptions;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\Localization\PortalLocale;
use App\Support\Tenancy\DatabaseTenancy;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Online intake: a public request for consultation is conflict-checked on
 * arrival (the applicant and every opposing party), reviewed by the firm,
 * then scheduled, declined or turned into a client and matter.
 */
class IntakeService
{
    public function __construct(
        private readonly ConflictChecker $conflicts,
        private readonly OpenMatter $openMatter,
        private readonly TenantContext $tenant,
        private readonly DatabaseTenancy $database,
    ) {}

    /** @param array<string, mixed> $data validated public form input */
    public function submit(Firm $firm, array $data, ?string $ip): IntakeRequest
    {
        $request = $this->tenant->runAs($firm->id, function () use ($firm, $data, $ip) {
            $this->database->restrictTo($firm->id);

            try {
                return DB::transaction(function () use ($firm, $data, $ip) {
                    $names = collect([$data['name'], ...($data['opposing_parties'] ?? [])])->map(fn ($n) => trim((string) $n))->filter()->unique();
                    $checks = $names->map(fn (string $name) => $this->conflicts->check($firm->id, $name, null));

                    return IntakeRequest::create([
                        'firm_id' => $firm->id,
                        'name' => $data['name'],
                        'email' => $data['email'],
                        'phone' => $data['phone'] ?? null,
                        'client_type' => $data['client_type'],
                        'case_type' => $data['case_type'],
                        'description' => $data['description'],
                        'opposing_parties' => array_values(array_filter($data['opposing_parties'] ?? [])),
                        'preferred_times' => array_values($data['preferred_times'] ?? []),
                        'consent_at' => now(),
                        'privacy_notice_version' => $firm->privacy_notice_version,
                        'ip_address' => $ip,
                        'conflict_check_ids' => $checks->pluck('id')->all(),
                        'conflict_status' => $checks->contains(fn ($c) => $c->status === ConflictCheckStatus::Flagged) ? 'flagged' : 'clear',
                        'incident_on' => $data['incident_on'] ?? null,
                        // The language the form was filled in: the applicant's emails follow it.
                        'locale' => PortalLocale::normalize(app()->getLocale()),
                    ]);
                });
            } finally {
                $this->database->bypass();
            }
        });

        Notification::route('mail', $request->email)->notify((new IntakeReceived($request, $firm))->locale($request->locale));
        Notification::send($this->reviewers($firm), new NewIntakeRequest($request));

        return $request;
    }

    public function schedule(IntakeRequest $request, CarbonImmutable $at, User $lawyer, User $by): IntakeRequest
    {
        $this->ensureOpen($request);

        $request->forceFill([
            'status' => IntakeRequest::SCHEDULED,
            'consultation_at' => $at,
            'assigned_lawyer_id' => $lawyer->id,
            'reviewed_by' => $by->id,
        ])->save();

        Notification::route('mail', $request->email)->notify((new ConsultationScheduled($request->load('assignedLawyer'), Firm::findOrFail($request->firm_id)))->locale($request->locale));

        return $request;
    }

    /** The applicant is never told why: conflicts of interest are confidential. */
    public function decline(IntakeRequest $request, ?string $notes, bool $notify, User $by): IntakeRequest
    {
        $this->ensureOpen($request);

        $request->forceFill(['status' => IntakeRequest::DECLINED, 'internal_notes' => $notes, 'reviewed_by' => $by->id])->save();
        AuditLog::record('intake_declined', $request->firm_id, $by, null, ['intake_request_id' => $request->id]);

        if ($notify) {
            Notification::route('mail', $request->email)->notify((new IntakeDeclined($request, Firm::findOrFail($request->firm_id)))->locale($request->locale));
        }

        return $request;
    }

    /**
     * Engage the applicant: create (or reuse) the client and open the matter,
     * with the opposing parties recorded for future conflict searches.
     */
    public function accept(IntakeRequest $request, User $by, ?string $title = null, ?int $lawyerId = null): Matter
    {
        $this->ensureOpen($request);

        foreach ($request->conflictChecks() as $check) {
            if ($check->status === ConflictCheckStatus::Flagged) {
                throw ValidationException::withMessages(['conflicts' => 'Resolve the flagged conflict check for "'.$check->search_term.'" (Compliance → Conflict checks) before accepting.']);
            }
            if ($check->status === ConflictCheckStatus::Declined) {
                throw ValidationException::withMessages(['conflicts' => 'A conflict check for "'.$check->search_term.'" was declined; this engagement cannot be accepted.']);
            }
        }

        return DB::transaction(function () use ($request, $by, $title, $lawyerId) {
            $client = Client::query()->whereRaw('lower(email) = ?', [mb_strtolower($request->email)])->first()
                ?? Client::create([
                    'firm_id' => $request->firm_id,
                    'type' => $request->client_type,
                    'name' => $request->name,
                    'email' => $request->email,
                    'phone' => $request->phone,
                ]);

            $matter = $this->openMatter->execute([
                'firm_id' => $request->firm_id,
                'client_id' => $client->id,
                'title' => $title ?: "{$request->name}: {$request->case_type}",
                'case_type' => $request->case_type,
                'description' => $request->description,
                'responsible_lawyer_id' => $lawyerId ?? $request->assigned_lawyer_id,
            ], $by, array_map(fn (string $name) => ['role' => PartyRole::AdverseParty->value, 'name' => $name], $request->opposing_parties ?? []));

            $request->forceFill([
                'status' => IntakeRequest::ACCEPTED,
                'client_id' => $client->id,
                'matter_id' => $matter->id,
                'reviewed_by' => $by->id,
            ])->save();

            // A prescription screened at intake is tracked on the matter from now on.
            app(Prescriptions::class)->startFromIntake($request, $matter, $by);

            AuditLog::record('intake_accepted', $request->firm_id, $by, $matter, ['intake_request_id' => $request->id]);

            return $matter;
        });
    }

    private function ensureOpen(IntakeRequest $request): void
    {
        if (! $request->isOpen()) {
            throw ValidationException::withMessages(['status' => "This request is already {$request->status}."]);
        }
    }

    /** Who hears about new requests: partners, or every lawyer in a small firm without one. */
    private function reviewers(Firm $firm)
    {
        $partners = User::withoutGlobalScopes()->where('firm_id', $firm->id)->where('is_active', true)
            ->whereIn('role', [Role::ManagingPartner->value, Role::Partner->value])->get();

        return $partners->isNotEmpty() ? $partners : User::withoutGlobalScopes()->where('firm_id', $firm->id)->where('is_active', true)
            ->whereIn('role', array_map(fn (Role $r) => $r->value, array_filter(Role::cases(), fn (Role $r) => $r->isLawyer())))->get();
    }
}
