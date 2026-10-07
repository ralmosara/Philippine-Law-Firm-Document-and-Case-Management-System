<?php

namespace App\Domain\Compliance\Services;

use App\Domain\Compliance\Models\ConflictCheck;
use App\Domain\Compliance\Models\ConflictWaiver;
use App\Domain\Compliance\Notifications\ConflictWaiverAnswered;
use App\Domain\Compliance\Notifications\ConflictWaiverRequested;
use App\Domain\Matters\Models\Firm;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Written informed consent to the firm acting despite a possible conflict
 * of interest: drafted by the lawyer from the facts and their explanation,
 * signed online by each person concerned through a private link, and kept
 * with the conflict check. Whether the conflict can be waived at all is the
 * lawyer's judgment; this only obtains and keeps the consent.
 */
class ConflictWaivers
{
    public const DAYS_TO_SIGN = 30;

    public function compose(ConflictCheck $check, string $signer, string $situation, string $explanation, User $lawyer): string
    {
        $firm = Firm::findOrFail($check->firm_id);

        return implode("\n\n", [
            now()->format('F j, Y'),
            $signer,
            'Re: Consent to our acting despite a possible conflict of interest',
            "Dear {$signer}:",
            trim($situation),
            trim($explanation),
            'Under the Code of Professional Responsibility and Accountability, a lawyer may act where there is a conflict of interest only with the written informed consent of all concerned, given after full disclosure of the facts. We will keep confidential everything you have told us and will not use it against you. You are free to refuse, and to consult another lawyer before deciding.',
            "If you consent, please sign below.\n\nVery truly yours,\n\n\n".mb_strtoupper($lawyer->name)."\n{$firm->name}",
            "CONSENT:\n\nI have read this letter and understand the possible conflict of interest and its effects. I consent to {$firm->name} acting as described above.",
        ]);
    }

    /** Fix the text and email the signer a private link. */
    public function send(ConflictCheck $check, string $name, string $email, string $content, User $by): ConflictWaiver
    {
        $token = Str::random(48);
        $waiver = DB::transaction(function () use ($check, $name, $email, $content, $by, $token) {
            $waiver = ConflictWaiver::create([
                'firm_id' => $check->firm_id,
                'conflict_check_id' => $check->id,
                'signer_name' => $name,
                'signer_email' => $email,
                'content' => $content,
                'content_sha256' => hash('sha256', $content),
                'created_by' => $by->id,
            ]);
            $waiver->forceFill(['token_hash' => hash('sha256', $token), 'sent_at' => now(), 'expires_at' => now()->addDays(self::DAYS_TO_SIGN)->endOfDay()])->save();

            return $waiver;
        });

        Notification::route('mail', $email)->notify(new ConflictWaiverRequested($waiver, Firm::findOrFail($check->firm_id)->name, $token));
        AuditLog::record('conflict_waiver_sent', $check->firm_id, $by, $waiver, ['check' => $check->id, 'to' => $email]);

        return $waiver;
    }

    public function cancel(ConflictWaiver $waiver): ConflictWaiver
    {
        if ($waiver->status !== 'sent') {
            throw ValidationException::withMessages(['waiver' => "This consent is {$waiver->status}."]);
        }
        $waiver->forceFill(['status' => 'cancelled', 'token_hash' => null])->save();

        return $waiver;
    }

    public function findByToken(string $token): ?ConflictWaiver
    {
        return ConflictWaiver::withoutGlobalScopes()->where('token_hash', hash('sha256', $token))->first();
    }

    /** @param 'drawn'|'typed' $method */
    public function sign(ConflictWaiver $waiver, string $name, string $method, ?string $image, ?string $ip, ?string $userAgent): ConflictWaiver
    {
        $waiver = DB::transaction(function () use ($waiver, $name, $method, $image, $ip, $userAgent) {
            $waiver = $this->lockOpen($waiver);
            if (! hash_equals($waiver->content_sha256, hash('sha256', $waiver->content))) {
                throw ValidationException::withMessages(['letter' => 'This letter has changed since it was sent. Please contact the firm.']);
            }
            $waiver->forceFill([
                'status' => 'signed', 'responded_at' => now(), 'signed_name' => $name, 'signature_method' => $method,
                'signature_image' => $method === 'drawn' ? $image : null, 'signer_ip' => $ip,
                'signer_user_agent' => $userAgent ? mb_substr($userAgent, 0, 512) : null, 'token_hash' => null,
            ])->save();

            return $waiver;
        });
        $this->tellLawyer($waiver);

        return $waiver;
    }

    public function decline(ConflictWaiver $waiver, ?string $reason, ?string $ip, ?string $userAgent): ConflictWaiver
    {
        $waiver = DB::transaction(function () use ($waiver, $reason, $ip, $userAgent) {
            $waiver = $this->lockOpen($waiver);
            $waiver->forceFill(['status' => 'declined', 'responded_at' => now(), 'decline_reason' => $reason, 'signer_ip' => $ip, 'signer_user_agent' => $userAgent ? mb_substr($userAgent, 0, 512) : null, 'token_hash' => null])->save();

            return $waiver;
        });
        $this->tellLawyer($waiver);

        return $waiver;
    }

    private function tellLawyer(ConflictWaiver $waiver): void
    {
        $check = ConflictCheck::withoutGlobalScopes()->find($waiver->conflict_check_id);
        User::withoutGlobalScopes()->where('is_active', true)->find($waiver->created_by ?? $check?->requested_by)?->notify(new ConflictWaiverAnswered($waiver, $check));
    }

    private function lockOpen(ConflictWaiver $waiver): ConflictWaiver
    {
        $locked = ConflictWaiver::withoutGlobalScopes()->whereKey($waiver->id)->lockForUpdate()->firstOrFail();
        if ($locked->status !== 'sent') {
            throw ValidationException::withMessages(['letter' => "This letter is {$locked->status}."]);
        }
        if ($locked->isExpired()) {
            throw ValidationException::withMessages(['letter' => 'This signing link has expired. Please ask the firm for a new one.']);
        }

        return $locked;
    }
}
