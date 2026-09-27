<?php

namespace App\Domain\Deadlines\Services;

use App\Domain\Deadlines\Enums\DeadlineStatus;
use App\Domain\Deadlines\Enums\ReminderStage;
use App\Domain\Deadlines\Jobs\SendDeadlineReminder;
use App\Domain\Deadlines\Models\MatterDeadline;
use App\Domain\Deadlines\Notifications\DeadlineMissed;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The scheduled heartbeat of the reminder pipeline. Safe to run as often as
 * desired: each deadline advances through reminder stages at most once, and
 * the stage is claimed with a conditional update, so overlapping runs cannot
 * send duplicates.
 */
class ReminderDispatcher
{
    /**
     * @return array{reminders: int, missed: int}
     */
    public function run(?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();

        return [
            'reminders' => $this->dispatchReminders($today),
            'missed' => $this->markMissed($today),
        ];
    }

    private function dispatchReminders(CarbonImmutable $today): int
    {
        $horizon = $today->addDays(ReminderStage::SevenDays->daysBefore());
        $dispatched = 0;

        MatterDeadline::withoutGlobalScopes()
            ->pending()
            ->whereBetween('due_date', [$today->toDateString(), $horizon->toDateString()])
            ->orderBy('id')
            ->chunkById(200, function ($deadlines) use ($today, &$dispatched) {
                foreach ($deadlines as $deadline) {
                    $daysRemaining = (int) $today->diffInDays($deadline->due_date, false);
                    $stage = ReminderStage::forDaysRemaining($daysRemaining);

                    if ($stage === null || ($deadline->last_reminder_stage?->rank() ?? 0) >= $stage->rank()) {
                        continue;
                    }

                    if ($this->claimStage($deadline, $stage)) {
                        SendDeadlineReminder::dispatch($deadline->id, $stage);
                        $dispatched++;
                    }
                }
            });

        return $dispatched;
    }

    /**
     * Atomically move the deadline to the new stage, only if no other worker
     * has moved it since we read it.
     */
    private function claimStage(MatterDeadline $deadline, ReminderStage $stage): bool
    {
        return DB::table('matter_deadlines')
            ->where('id', $deadline->id)
            ->where('status', DeadlineStatus::Pending->value)
            ->where(fn ($query) => $deadline->last_reminder_stage === null
                ? $query->whereNull('last_reminder_stage')
                : $query->where('last_reminder_stage', $deadline->last_reminder_stage->value))
            ->update(['last_reminder_stage' => $stage->value, 'updated_at' => now()]) === 1;
    }

    private function markMissed(CarbonImmutable $today): int
    {
        $missed = 0;

        MatterDeadline::withoutGlobalScopes()
            ->pending()
            ->where('due_date', '<', $today->toDateString())
            ->with(['assignee', 'matter.responsibleLawyer'])
            ->chunkById(200, function ($deadlines) use (&$missed) {
                foreach ($deadlines as $deadline) {
                    $updated = DB::table('matter_deadlines')
                        ->where('id', $deadline->id)
                        ->where('status', DeadlineStatus::Pending->value)
                        ->update(['status' => DeadlineStatus::Missed->value, 'updated_at' => now()]);

                    if ($updated !== 1) {
                        continue;
                    }

                    $deadline->logEvent('missed');
                    $recipients = collect([$deadline->assignee, $deadline->matter?->responsibleLawyer])->filter()->unique('id');
                    $recipients->each->notify(new DeadlineMissed($deadline));
                    $missed++;
                }
            });

        return $missed;
    }
}
