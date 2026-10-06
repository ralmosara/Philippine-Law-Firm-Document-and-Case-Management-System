<?php

namespace App\Domain\Business;

use App\Domain\Business\Models\EngagementLetter;
use App\Domain\Business\Models\Prospect;
use App\Domain\Business\Notifications\EngagementLetterAnswered;
use App\Domain\Business\Notifications\EngagementLetterSent;
use App\Domain\Compliance\Enums\ConflictCheckStatus;
use App\Domain\Documents\Actions\CreateDocumentVersion;
use App\Domain\Documents\Enums\DocumentStatus;
use App\Domain\Matters\Enums\FeeArrangement;
use App\Domain\Matters\Models\Firm;
use App\Domain\Matters\Models\Matter;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Engagement letters, from draft to signed client. The lawyer drafts the
 * letter from the agreed fees and scope (and may edit the text), then sends
 * the prospect a private signing link: they have no portal account yet.
 * Signing records the same evidence as any e-signature (the exact text's
 * hash, name, method, IP, time) and converts the prospect into a client and
 * matter, with the fee terms set and the signed letter filed on the matter.
 */
class EngagementLetters
{
    public const DAYS_TO_SIGN = 30;

    public function __construct(private readonly Pipeline $pipeline) {}

    /**
     * @param  array{fee_arrangement: string, fixed_fee_cents?: ?int, acceptance_fee_cents?: ?int, appearance_fee_cents?: ?int, contingency_basis_points?: ?int, scope: string, content?: ?string}  $terms
     */
    public function draft(Prospect $prospect, array $terms, User $by): EngagementLetter
    {
        if (! $prospect->isOpen()) {
            throw ValidationException::withMessages(['prospect' => 'This prospect is closed.']);
        }
        $letter = EngagementLetter::query()->where('prospect_id', $prospect->id)->where('status', 'draft')->latest('id')->first()
            ?? new EngagementLetter(['firm_id' => $prospect->firm_id, 'prospect_id' => $prospect->id, 'created_by' => $by->id]);

        $letter->fill([
            'fee_arrangement' => $terms['fee_arrangement'],
            'fixed_fee_cents' => $terms['fixed_fee_cents'] ?? null,
            'acceptance_fee_cents' => $terms['acceptance_fee_cents'] ?? null,
            'appearance_fee_cents' => $terms['appearance_fee_cents'] ?? null,
            'contingency_basis_points' => $terms['contingency_basis_points'] ?? null,
            'scope' => trim($terms['scope']),
        ]);
        $letter->content = filled($terms['content'] ?? null) ? trim($terms['content']) : $this->compose($prospect, $letter);
        $letter->save();

        return $letter;
    }

    /** The letter's text from the terms: the firm's standard engagement clauses. */
    public function compose(Prospect $prospect, EngagementLetter $letter): string
    {
        $firm = Firm::findOrFail($prospect->firm_id);
        $lawyer = User::find($prospect->owner_id);
        $peso = fn (?int $c) => '₱'.number_format(($c ?? 0) / 100, 2);
        $client = $prospect->organization && $prospect->client_type === 'corporate' ? $prospect->organization : $prospect->name;

        $fees = match ($letter->fee_arrangement) {
            FeeArrangement::Flat => ["A fixed fee of {$peso($letter->fixed_fee_cents)} for the services described above."],
            FeeArrangement::Retainer => ["A monthly retainer of {$peso($letter->fixed_fee_cents)}, billed at the start of each month while this engagement continues."],
            FeeArrangement::Contingency => [rtrim(rtrim(number_format(($letter->contingency_basis_points ?? 0) / 100, 2), '0'), '.').'% of the amount actually recovered for you, whether by judgment, settlement or otherwise, payable when it is received.'],
            FeeArrangement::ProBono => ['We will not charge professional fees for this engagement.'],
            default => ['Our professional fees are based on the time spent, at the standard hourly rates of the lawyers who work on your matter'.($lawyer?->hourly_rate_cents ? " ({$lawyer->name}: {$peso($lawyer->hourly_rate_cents)} per hour)" : '').', recorded in units of six minutes.'],
        };
        if ($letter->acceptance_fee_cents) {
            array_unshift($fees, "An acceptance fee of {$peso($letter->acceptance_fee_cents)}, payable on signing this letter, for accepting the engagement and the work of opening the matter.");
        }
        if ($letter->appearance_fee_cents) {
            $fees[] = "An appearance fee of {$peso($letter->appearance_fee_cents)} for each hearing, conference or mediation we attend for you.";
        }
        $taxes = $firm->vat_registered
            ? 'Professional fees are subject to 12% VAT. If you are required to withhold creditable tax on our fees, please give us BIR Form 2307 for each payment.'
            : 'If you are required to withhold creditable tax on our fees, please give us BIR Form 2307 for each payment.';

        $sections = [
            now()->format('F j, Y'),
            $client,
            "Re: Engagement of {$firm->name}".($prospect->case_type ? " ({$prospect->case_type})" : ''),
            "Dear {$prospect->name}:",
            "Thank you for choosing {$firm->name}. This letter sets out the terms on which we will act for you. Please read it carefully; by signing below you confirm that you agree to them.",
            "1. Scope of our engagement\n\n{$letter->scope}\n\nOur engagement does not include appeals, related cases or other matters unless we agree in writing.",
            "2. Professional fees\n\n".implode("\n\n", array_map(fn ($f) => "• {$f}", $fees))."\n\n{$taxes}",
            '3. Expenses'."\n\n".'Filing and docket fees, transcripts, notarial fees, courier, travel outside the city and similar costs are billed at cost, without mark-up. We may ask you to deposit an amount to cover them; it will be held in trust for you, accounted for, and any balance returned when the engagement ends.',
            '4. Billing'."\n\n".'We will send billing statements as fees and expenses are incurred. Each is due within 30 days. You will also receive a statement of your account each month while anything is owed or held in trust.',
            '5. Your part'."\n\n".'Please give us complete and truthful information and documents, tell us promptly of any change that may affect the matter, and keep us informed of how to reach you.',
            '6. No guarantee'."\n\n".'We will handle your matter with competence and diligence, but we cannot and do not guarantee any particular outcome.',
            '7. Conflicts of interest'."\n\n".'We have checked our records for conflicts of interest with you and the parties you have named and found none that prevents us from acting. Please tell us if you know of any other party involved.',
            '8. Confidentiality and personal data'."\n\n".'We keep your information confidential. By signing, you consent to our collecting and processing the personal data you give us, and that of the persons involved in the matter, for this engagement, in accordance with the Data Privacy Act of 2012 (RA 10173) and our privacy notice, which we will give you on request.'.($firm->dpo_email ? " Our Data Protection Officer can be reached at {$firm->dpo_email}." : ''),
            '9. Ending the engagement'."\n\n".'You may end this engagement at any time by telling us in writing. We may withdraw for good cause as the Code of Professional Responsibility and Accountability allows, with the court\'s approval where required. Fees earned and expenses incurred up to then remain payable.',
            "If these terms are acceptable, please sign below. We look forward to working with you.\n\nVery truly yours,\n\n\n".mb_strtoupper($lawyer?->name ?? $firm->name)."\n{$firm->name}",
            "CONFORME:\n\nI have read and agree to the terms of this engagement.",
        ];

        return implode("\n\n", $sections);
    }

    /** Fix the text and email the prospect a private link to sign. */
    public function send(EngagementLetter $letter, User $by): EngagementLetter
    {
        $prospect = Prospect::findOrFail($letter->prospect_id);
        if ($letter->status !== 'draft') {
            throw ValidationException::withMessages(['letter' => 'This letter has already been sent.']);
        }
        if (blank($prospect->email)) {
            throw ValidationException::withMessages(['letter' => 'Add the prospect\'s email address first.']);
        }
        foreach ($prospect->conflictChecks() as $check) {
            if (in_array($check->status, [ConflictCheckStatus::Flagged, ConflictCheckStatus::Declined], true)) {
                throw ValidationException::withMessages(['letter' => "Resolve the conflict check for \"{$check->search_term}\" first."]);
            }
        }

        $token = Str::random(48);
        DB::transaction(function () use ($letter, $token, $prospect, $by) {
            // Any earlier letter still waiting is withdrawn: only one can be signed.
            EngagementLetter::query()->where('prospect_id', $prospect->id)->where('status', 'sent')->update(['status' => 'cancelled', 'token_hash' => null]);
            $letter->forceFill([
                'status' => 'sent',
                'content_sha256' => hash('sha256', $letter->content),
                'token_hash' => EngagementLetter::hashToken($token),
                'sent_at' => now(),
                'expires_at' => now()->addDays(self::DAYS_TO_SIGN)->endOfDay(),
            ])->save();
            if ($prospect->stage !== 'engagement_sent') {
                $this->pipeline->move($prospect, 'engagement_sent', $by, 'Engagement letter sent for signature');
            }
        });

        Notification::route('mail', $prospect->email)->notify(new EngagementLetterSent($letter, $prospect->name, Firm::findOrFail($letter->firm_id)->name, $token));

        return $letter;
    }

    public function cancel(EngagementLetter $letter): EngagementLetter
    {
        if (! in_array($letter->status, EngagementLetter::OPEN, true)) {
            throw ValidationException::withMessages(['letter' => "This letter is {$letter->status}."]);
        }
        $letter->forceFill(['status' => 'cancelled', 'token_hash' => null])->save();

        return $letter;
    }

    /** The letter behind a signing link, across firms (the link is the credential). */
    public function findByToken(string $token): ?EngagementLetter
    {
        return EngagementLetter::withoutGlobalScopes()->where('token_hash', EngagementLetter::hashToken($token))->first();
    }

    /**
     * Sign, then convert the prospect into a client and matter. A conversion
     * that cannot happen (say a conflict was flagged meanwhile) leaves the
     * signature recorded and tells the lawyer to finish it by hand.
     *
     * @param  'drawn'|'typed'  $method
     */
    public function sign(EngagementLetter $letter, string $signerName, string $method, ?string $image, ?string $ip, ?string $userAgent): EngagementLetter
    {
        $letter = DB::transaction(function () use ($letter, $signerName, $method, $image, $ip, $userAgent) {
            $letter = $this->lockOpen($letter);
            if (! hash_equals((string) $letter->content_sha256, hash('sha256', $letter->content))) {
                throw ValidationException::withMessages(['letter' => 'This letter has changed since it was sent. Please contact the firm.']);
            }
            $letter->forceFill([
                'status' => 'signed',
                'responded_at' => now(),
                'signer_name' => $signerName,
                'signature_method' => $method,
                'signature_image' => $method === 'drawn' ? $image : null,
                'signer_ip' => $ip,
                'signer_user_agent' => $userAgent ? mb_substr($userAgent, 0, 512) : null,
                'token_hash' => null,
            ])->save();

            return $letter;
        });

        $prospect = Prospect::findOrFail($letter->prospect_id);
        $owner = User::find($prospect->owner_id) ?? User::where('firm_id', $letter->firm_id)->where('role', 'managing_partner')->first();
        $matter = null;
        try {
            $matter = $this->open($letter, $prospect, $owner);
        } catch (ValidationException $e) {
            Log::info('Engagement signed but not converted', ['letter' => $letter->id, 'errors' => $e->errors()]);
        }
        $owner?->notify(new EngagementLetterAnswered($letter, $prospect, $matter));

        return $letter;
    }

    public function decline(EngagementLetter $letter, ?string $reason, ?string $ip, ?string $userAgent): EngagementLetter
    {
        $letter = DB::transaction(function () use ($letter, $reason, $ip, $userAgent) {
            $letter = $this->lockOpen($letter);
            $letter->forceFill(['status' => 'declined', 'responded_at' => now(), 'decline_reason' => $reason, 'signer_ip' => $ip, 'signer_user_agent' => $userAgent ? mb_substr($userAgent, 0, 512) : null, 'token_hash' => null])->save();

            return $letter;
        });
        $prospect = Prospect::findOrFail($letter->prospect_id);
        User::find($prospect->owner_id)?->notify(new EngagementLetterAnswered($letter, $prospect, null));

        return $letter;
    }

    /** Convert, set the agreed fees on the matter, and file the signed letter there. */
    private function open(EngagementLetter $letter, Prospect $prospect, ?User $by): Matter
    {
        if ($by === null) {
            throw ValidationException::withMessages(['letter' => 'No lawyer to open the matter.']);
        }

        return app(TenantContext::class)->runAs($letter->firm_id, fn () => DB::transaction(function () use ($letter, $prospect, $by) {
            $matter = $this->pipeline->convert($prospect, $by);
            $matter->forceFill([
                'fee_arrangement' => $letter->fee_arrangement,
                'fixed_fee_cents' => $letter->fixed_fee_cents,
                'acceptance_fee_cents' => $letter->acceptance_fee_cents,
                'appearance_fee_cents' => $letter->appearance_fee_cents,
                'contingency_basis_points' => $letter->contingency_basis_points,
            ])->save();

            $document = $matter->documents()->create(['firm_id' => $matter->firm_id, 'title' => 'Engagement letter (signed)', 'created_by' => $by->id]);
            $signature = "\n\n".($letter->signature_method === 'typed' ? "/s/ {$letter->signer_name}" : "[Signed electronically: {$letter->signer_name}]")
                ."\nSigned ".$letter->responded_at->timezone('Asia/Manila')->format('F j, Y g:i A')." from {$letter->signer_ip}. Text fingerprint (SHA-256): {$letter->content_sha256}";
            app(CreateDocumentVersion::class)->execute($document, $letter->content.$signature, $by, 'Engagement letter signed electronically');
            $document->forceFill(['status' => DocumentStatus::Signed])->save();

            $letter->forceFill(['matter_id' => $matter->id, 'document_id' => $document->id])->save();
            AuditLog::record('engagement_signed', $letter->firm_id, $by, $matter, ['letter' => $letter->id, 'signer' => $letter->signer_name, 'sha256' => $letter->content_sha256]);

            return $matter;
        }), $by);
    }

    private function lockOpen(EngagementLetter $letter): EngagementLetter
    {
        $locked = EngagementLetter::withoutGlobalScopes()->whereKey($letter->id)->lockForUpdate()->firstOrFail();
        if ($locked->status !== 'sent') {
            throw ValidationException::withMessages(['letter' => "This letter is {$locked->status}."]);
        }
        if ($locked->isExpired()) {
            throw ValidationException::withMessages(['letter' => 'This signing link has expired. Please ask the firm for a new one.']);
        }

        return $locked;
    }
}
