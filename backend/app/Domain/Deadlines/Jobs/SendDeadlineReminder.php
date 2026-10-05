<?php

namespace App\Domain\Deadlines\Jobs;

use App\Domain\Deadlines\Enums\ReminderStage;
use App\Domain\Deadlines\Models\MatterDeadline;
use App\Domain\Deadlines\Notifications\DeadlineReminder;
use App\Domain\Staff\OutOfOffice;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Notifies the people responsible for a deadline that it is approaching,
 * then records the reminder in the deadline's event log.
 *
 * Retries with backoff so a transient mail/SMS outage delays a reminder
 * rather than dropping it.
 */
class SendDeadlineReminder implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [60, 300, 900, 1800];

    public function __construct(
        public int $matterDeadlineId,
        public ReminderStage $stage,
    ) {}

    public function handle(): void
    {
        $deadline = MatterDeadline::withoutGlobalScopes()
            ->with(['assignee', 'matter.responsibleLawyer'])
            ->find($this->matterDeadlineId);

        // The deadline may have been completed or cancelled since dispatch.
        if ($deadline === null || ! $deadline->status->isOpen()) {
            return;
        }

        $recipients = OutOfOffice::withCover(collect([$deadline->assignee, $deadline->matter?->responsibleLawyer])
            ->filter(fn ($user) => $user !== null && $user->is_active)
            ->unique('id'));

        $recipients->each->notify(new DeadlineReminder($deadline, $this->stage));

        $deadline->logEvent('reminder_sent', payload: [
            'stage' => $this->stage->value,
            'recipients' => $recipients->pluck('id')->values()->all(),
        ]);
    }
}
