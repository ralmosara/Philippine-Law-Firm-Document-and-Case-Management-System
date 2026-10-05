<?php

namespace App\Domain\Deadlines;

use App\Domain\Deadlines\Enums\DeadlineKind;
use App\Domain\Deadlines\Enums\DeadlineStatus;
use App\Domain\Deadlines\Models\MatterDeadline;
use App\Domain\Deadlines\Notifications\ClientHearingNotice;
use App\Domain\Matters\Models\Client;
use App\Domain\Matters\Models\Firm;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Tells clients about their hearings, in their language, by email and SMS:
 * when one is set, moved or cancelled, and a reminder a week before and the
 * day before. Off until the firm turns it on; a client, or a single hearing
 * the client need not attend, can be left out.
 */
class ClientHearingNotices
{
    public const WEEK = 'week';

    public const DAY = 'day';

    /** A new hearing was set. */
    public function scheduled(MatterDeadline $hearing): void
    {
        if ($hearing->due_date->gte(today())) {
            $this->notify($hearing, ClientHearingNotice::SCHEDULED);
        }
    }

    /** The hearing was moved: the reminders start over for the new date. */
    public function moved(MatterDeadline $hearing, string $from): void
    {
        $hearing->forceFill(['client_reminded_stage' => null])->saveQuietly();
        $this->notify($hearing, ClientHearingNotice::MOVED, $from);
    }

    public function cancelled(MatterDeadline $hearing): void
    {
        if ($hearing->due_date->gte(today())) {
            $this->notify($hearing, ClientHearingNotice::CANCELLED);
        }
    }

    /** Daily: a week before, and the day before. Each stage is sent once. */
    public function sendReminders(?CarbonImmutable $today = null): int
    {
        $today ??= CarbonImmutable::today();
        $sent = 0;

        Firm::query()->where('client_hearing_reminders', true)->pluck('id')->each(function (int $firmId) use ($today, &$sent) {
            app(TenantContext::class)->runAs($firmId, function () use ($today, &$sent) {
                MatterDeadline::query()
                    ->where('kind', DeadlineKind::Hearing->value)
                    ->where('status', DeadlineStatus::Pending->value)
                    ->where('notify_client', true)
                    ->whereBetween('due_date', [$today->addDay()->toDateString(), $today->addDays(7)->toDateString()])
                    ->get()
                    ->each(function (MatterDeadline $hearing) use ($today, &$sent) {
                        $days = (int) $today->diffInDays($hearing->due_date, false);
                        $stage = $days <= 1 ? self::DAY : self::WEEK;
                        if ($hearing->client_reminded_stage === self::DAY || $hearing->client_reminded_stage === $stage) {
                            return;
                        }
                        // Claimed atomically, so overlapping runs cannot send twice.
                        $claimed = DB::table('matter_deadlines')->where('id', $hearing->id)
                            ->where(fn ($q) => $hearing->client_reminded_stage === null ? $q->whereNull('client_reminded_stage') : $q->where('client_reminded_stage', $hearing->client_reminded_stage))
                            ->update(['client_reminded_stage' => $stage]) === 1;
                        if ($claimed && $this->notify($hearing, $stage === self::DAY ? ClientHearingNotice::TOMORROW : ClientHearingNotice::NEXT_WEEK)) {
                            $sent++;
                        }
                    });
            });
        });

        return $sent;
    }

    private function notify(MatterDeadline $hearing, string $kind, ?string $from = null): bool
    {
        if ($hearing->kind !== DeadlineKind::Hearing || ! $hearing->notify_client) {
            return false;
        }

        $hearing->loadMissing('matter:id,firm_id,client_id,title,reference,court,court_branch');
        $firm = Firm::find($hearing->matter?->firm_id);
        $client = $hearing->matter ? Client::find($hearing->matter->client_id) : null;
        if (! $firm?->client_hearing_reminders || $client === null || ! $client->hearing_reminders || (blank($client->email) && blank($client->phone)) || $client->anonymized_at !== null) {
            return false;
        }

        DB::afterCommit(fn () => $client->notify(new ClientHearingNotice($hearing, $firm, $kind, $from)));

        return true;
    }
}
