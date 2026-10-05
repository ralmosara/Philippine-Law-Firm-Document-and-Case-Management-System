<?php

namespace App\Domain\Deadlines\Services;

use App\Domain\Deadlines\Enums\DeadlineKind;
use App\Domain\Deadlines\Models\MatterDeadline;
use App\Domain\Matters\Models\Matter;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * A repeating task: when one is finished, the next is created with the
 * same title, assignee, priority and notes, due one interval after the
 * last due date (skipping ahead past today if it was finished very late).
 * Each task makes its next one only once, so reopening and finishing
 * again does not duplicate it. It stops after the end date, or once the
 * matter is closed.
 */
class RecurringTasks
{
    public const REPEATS = ['weekly' => 'Every week', 'monthly' => 'Every month', 'quarterly' => 'Every 3 months', 'yearly' => 'Every year'];

    public function afterCompleted(MatterDeadline $task, User $by): ?MatterDeadline
    {
        if ($task->kind !== DeadlineKind::Task || $task->repeat === null || $task->next_task_id !== null) {
            return null;
        }
        $matter = Matter::find($task->matter_id);
        if ($matter === null || ! $matter->status->isActive()) {
            return null;
        }

        $today = CarbonImmutable::today();
        $due = $this->next(CarbonImmutable::instance($task->due_date), $task->repeat);
        while ($due->lt($today)) {
            $due = $this->next($due, $task->repeat);
        }
        if ($task->repeat_until !== null && $due->gt($task->repeat_until)) {
            return null;
        }

        return DB::transaction(function () use ($task, $matter, $by, $due) {
            // Claimed first, so two completions at once cannot both create it.
            $locked = MatterDeadline::whereKey($task->id)->lockForUpdate()->first();
            if ($locked->next_task_id !== null) {
                return null;
            }

            $next = app(DeadlineScheduler::class)->scheduleManual($matter, $by, [
                'kind' => DeadlineKind::Task,
                'title' => $task->title,
                'due_date' => $due->toDateString(),
                'due_time' => $task->due_time,
                'assigned_to' => $task->assigned_to,
                'notes' => $task->notes,
                'priority' => $task->priority,
                'repeat' => $task->repeat,
                'repeat_until' => $task->repeat_until?->toDateString(),
            ]);
            $locked->forceFill(['next_task_id' => $next->id])->saveQuietly();
            $task->next_task_id = $next->id;

            return $next;
        });
    }

    public function next(CarbonImmutable $from, string $repeat): CarbonImmutable
    {
        return match ($repeat) {
            'weekly' => $from->addWeek(),
            'monthly' => $from->addMonthNoOverflow(),
            'quarterly' => $from->addMonthsNoOverflow(3),
            default => $from->addYearNoOverflow(),
        };
    }
}
