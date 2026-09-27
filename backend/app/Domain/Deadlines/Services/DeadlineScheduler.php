<?php

namespace App\Domain\Deadlines\Services;

use App\Domain\Deadlines\Enums\DeadlineKind;
use App\Domain\Deadlines\Enums\DeadlineStatus;
use App\Domain\Deadlines\Enums\TaskProgress;
use App\Domain\Deadlines\Models\DeadlineRule;
use App\Domain\Deadlines\Models\MatterDeadline;
use App\Domain\Matters\Models\Matter;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates and moves matter deadlines, keeping the append-only deadline
 * event log in step with every change.
 */
class DeadlineScheduler
{
    public function __construct(private readonly DeadlineCalculator $calculator) {}

    /**
     * Schedule a deadline from a reglementary rule and its trigger date
     * (e.g. date of receipt of the adverse judgment).
     */
    public function scheduleFromRule(Matter $matter, DeadlineRule $rule, CarbonImmutable $triggerDate, User $by, array $attributes = []): MatterDeadline
    {
        $computation = $this->calculator->compute($triggerDate, $rule->period_days, $rule->period_type);

        return $this->create($matter, $by, [
            'kind' => DeadlineKind::Filing,
            'title' => $rule->name,
            ...$attributes,
            'deadline_rule_id' => $rule->id,
            'trigger_date' => $triggerDate,
            'due_date' => $computation->dueDate,
        ], ['computation' => $computation->toArray(), 'rule' => $rule->name]);
    }

    /** Schedule a hearing, task, or manually computed deadline. */
    public function scheduleManual(Matter $matter, User $by, array $attributes): MatterDeadline
    {
        return $this->create($matter, $by, $attributes);
    }

    public function complete(MatterDeadline $deadline, User $by, ?string $notes = null): MatterDeadline
    {
        $this->ensureOpen($deadline);

        return DB::transaction(function () use ($deadline, $by, $notes) {
            $deadline->forceFill([
                'status' => DeadlineStatus::Completed,
                'completed_at' => now(),
                'completed_by' => $by->id,
            ])->save();
            $deadline->logEvent('completed', $by, array_filter(['notes' => $notes]));

            return $deadline;
        });
    }

    public function cancel(MatterDeadline $deadline, User $by, string $reason): MatterDeadline
    {
        $this->ensureOpen($deadline);

        return DB::transaction(function () use ($deadline, $by, $reason) {
            $deadline->forceFill(['status' => DeadlineStatus::Cancelled])->save();
            $deadline->logEvent('cancelled', $by, ['reason' => $reason]);

            return $deadline;
        });
    }

    /**
     * Move a deadline (e.g. a granted motion for extension or a reset
     * hearing). Resets the reminder cycle for the new date.
     */
    public function reschedule(MatterDeadline $deadline, CarbonImmutable $newDueDate, User $by, string $reason): MatterDeadline
    {
        $this->ensureOpen($deadline);

        return DB::transaction(function () use ($deadline, $newDueDate, $by, $reason) {
            $previous = $deadline->due_date->toDateString();

            $deadline->forceFill([
                'due_date' => $newDueDate,
                'last_reminder_stage' => null,
            ])->save();
            $deadline->logEvent('rescheduled', $by, [
                'from' => $previous,
                'to' => $newDueDate->toDateString(),
                'reason' => $reason,
            ]);

            return $deadline;
        });
    }

    private function create(Matter $matter, User $by, array $attributes, array $eventPayload = []): MatterDeadline
    {
        return DB::transaction(function () use ($matter, $by, $attributes, $eventPayload) {
            $deadline = $matter->deadlines()->create([
                'firm_id' => $matter->firm_id,
                'assigned_to' => $attributes['assigned_to'] ?? $matter->responsible_lawyer_id,
                ...$attributes,
            ]);
            $deadline->logEvent('created', $by, $eventPayload);

            return $deadline;
        });
    }

    /**
     * Move a task across the board. "done" completes it; moving a completed
     * task back to another column reopens it. Every move is logged.
     *
     * @param  'todo'|'in_progress'|'review'|'done'  $column
     */
    public function moveTask(MatterDeadline $task, string $column, User $by): MatterDeadline
    {
        if ($task->kind !== DeadlineKind::Task) {
            throw ValidationException::withMessages(['column' => 'Only tasks can be moved on the board. Complete deadlines and hearings from their matter.']);
        }

        if ($column === 'done') {
            if ($task->status === DeadlineStatus::Completed) {
                return $task;
            }

            // An overdue ("missed") task can still be finished late.
            if ($task->status === DeadlineStatus::Missed) {
                return DB::transaction(function () use ($task, $by) {
                    $task->forceFill(['status' => DeadlineStatus::Completed, 'completed_at' => now(), 'completed_by' => $by->id])->save();
                    $task->logEvent('completed', $by, ['late' => true]);

                    return $task;
                });
            }

            return $this->complete($task, $by);
        }

        $progress = TaskProgress::from($column);

        return DB::transaction(function () use ($task, $progress, $by) {
            if ($task->status === DeadlineStatus::Completed) {
                $task->forceFill(['status' => DeadlineStatus::Pending, 'completed_at' => null, 'completed_by' => null])->save();
                $task->logEvent('reopened', $by);
            } elseif (! $task->status->isOpen() && $task->status !== DeadlineStatus::Missed) {
                throw ValidationException::withMessages(['column' => "This task is {$task->status->value}."]);
            }

            if ($task->progress !== $progress) {
                $from = $task->progress?->value;
                $task->forceFill(['progress' => $progress])->save();
                $task->logEvent('moved', $by, ['from' => $from, 'to' => $progress->value]);
            }

            return $task;
        });
    }

    private function ensureOpen(MatterDeadline $deadline): void
    {
        if (! $deadline->status->isOpen()) {
            throw ValidationException::withMessages([
                'status' => "This deadline is already {$deadline->status->value}.",
            ]);
        }
    }
}
