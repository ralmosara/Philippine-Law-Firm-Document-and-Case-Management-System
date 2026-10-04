<?php

namespace App\Domain\Budgets;

use App\Domain\Billing\Models\Expense;
use App\Domain\Billing\Models\TimeEntry;
use App\Domain\Budgets\Notifications\BudgetThresholdReached;
use App\Domain\Matters\Enums\MatterStatus;
use App\Domain\Matters\Models\Matter;
use App\Domain\Matters\Models\MatterStatusEvent;
use App\Enums\Role;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Tracks a matter's budget against what has been logged: billable time
 * (fees, or hours) and, for a peso budget, billable expenses. Spending is
 * put against the stage the matter was in on the day of the work, from
 * its status history. Lawyers are alerted once at 80% and once at 100%;
 * raising the budget (or removing entries) re-arms the alerts.
 */
class MatterBudgets
{
    public const THRESHOLDS = [80, 100];

    /**
     * @return array{used: int, total: int, percent: int, fees_cents: int, expenses_cents: int, minutes: int, by_stage: list<array{stage: string, label: string, total: int|null, used: int, percent: int|null}>}
     */
    public function usage(MatterBudget $budget): array
    {
        $matter = $budget->matter;
        $entries = TimeEntry::query()->where('matter_id', $matter->id)->where('is_billable', true)->get(['work_date', 'minutes', 'amount_cents']);
        $expenses = $budget->isHours() || ! $budget->include_expenses
            ? collect()
            : Expense::query()->where('matter_id', $matter->id)->where('is_billable', true)->get(['expense_date', 'amount_cents']);

        $stageOn = $this->stageOn($matter);
        $byStage = [];
        foreach ($entries as $entry) {
            $byStage[$stageOn($entry->work_date)] = ($byStage[$stageOn($entry->work_date)] ?? 0) + ($budget->isHours() ? $entry->minutes : $entry->amount_cents);
        }
        foreach ($expenses as $expense) {
            $byStage[$stageOn($expense->expense_date)] = ($byStage[$stageOn($expense->expense_date)] ?? 0) + $expense->amount_cents;
        }

        $used = (int) array_sum($byStage);
        $planned = collect($budget->stages ?? [])->keyBy('stage');
        $stages = collect(MatterStatus::cases())
            ->reject(fn (MatterStatus $s) => $s === MatterStatus::Closed)
            ->filter(fn (MatterStatus $s) => $planned->has($s->value) || ($byStage[$s->value] ?? 0) > 0)
            ->map(fn (MatterStatus $s) => [
                'stage' => $s->value,
                'label' => $s->label(),
                'total' => $planned->has($s->value) ? (int) $planned[$s->value]['total'] : null,
                'used' => (int) ($byStage[$s->value] ?? 0),
                'percent' => $planned->has($s->value) ? $this->percent((int) ($byStage[$s->value] ?? 0), (int) $planned[$s->value]['total']) : null,
            ])->values()->all();

        return [
            'used' => $used,
            'total' => $budget->total,
            'percent' => $this->percent($used, $budget->total),
            'fees_cents' => (int) $entries->sum('amount_cents'),
            'expenses_cents' => (int) $expenses->sum('amount_cents'),
            'minutes' => (int) $entries->sum('minutes'),
            'by_stage' => $stages,
        ];
    }

    /** After time or expenses change on a matter: alert at 80% and 100%, once each. */
    public function check(int $matterId): void
    {
        DB::transaction(function () use ($matterId) {
            $budget = MatterBudget::query()->where('matter_id', $matterId)->lockForUpdate()->first();
            if ($budget === null || $budget->total <= 0) {
                return;
            }

            $usage = $this->usage($budget);
            $reached = null;
            foreach (self::THRESHOLDS as $threshold) {
                $column = "alerted_{$threshold}_at";
                if ($usage['percent'] >= $threshold) {
                    if ($budget->{$column} === null) {
                        $budget->{$column} = now();
                        $reached = $threshold;
                    }
                } else {
                    $budget->{$column} = null; // fell back below: alert again next time
                }
            }
            $budget->saveQuietly();

            if ($reached !== null) {
                $budget->loadMissing('matter');
                DB::afterCommit(fn () => Notification::send($this->recipients($budget), new BudgetThresholdReached($budget, $reached, $usage)));
            }
        });
    }

    /** The responsible lawyer, whoever set the budget, and the managing partners. */
    private function recipients(MatterBudget $budget): Collection
    {
        return User::query()
            ->where('firm_id', $budget->firm_id)
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereIn('id', array_filter([$budget->matter->responsible_lawyer_id, $budget->updated_by ?? $budget->created_by]))
                ->orWhere('role', Role::ManagingPartner->value))
            ->get();
    }

    /** Stage the matter was in on a given day, from its status history. */
    private function stageOn(Matter $matter): \Closure
    {
        $events = MatterStatusEvent::query()->where('matter_id', $matter->id)->orderBy('created_at')->orderBy('id')->get(['from_status', 'to_status', 'created_at']);
        // Before the first recorded change: the stage it started in.
        $initial = $events->first()?->from_status ?? MatterStatus::Intake;

        return function (?CarbonInterface $day) use ($events, $initial): string {
            $stage = $initial;
            foreach ($events as $event) {
                if ($day === null || $event->created_at->timezone('Asia/Manila')->toDateString() > $day->toDateString()) {
                    break;
                }
                // Work logged while closed belongs to the stage it closed from.
                if ($event->to_status !== MatterStatus::Closed) {
                    $stage = $event->to_status;
                }
            }

            return $stage->value;
        };
    }

    private function percent(int $used, int $total): int
    {
        return $total > 0 ? (int) floor($used * 100 / $total) : 0;
    }
}
