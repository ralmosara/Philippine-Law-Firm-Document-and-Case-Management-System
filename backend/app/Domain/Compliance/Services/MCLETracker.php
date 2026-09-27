<?php

namespace App\Domain\Compliance\Services;

use App\Domain\Compliance\Models\McleCompliancePeriod;
use App\Domain\Compliance\Models\McleCredit;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Mandatory Continuing Legal Education compliance (Bar Matter 850): each
 * lawyer must complete the required units within each compliance period.
 */
class MCLETracker
{
    /**
     * @return array{period_id: int, period_name: string, period_end: string, required_units: int, earned_units: float, remaining_units: float, is_compliant: bool, percent: int}
     */
    public function getComplianceStatus(int $userId, int $periodId): array
    {
        $period = McleCompliancePeriod::findOrFail($periodId);

        $earned = (float) McleCredit::query()
            ->where('user_id', $userId)
            ->where('period_id', $periodId)
            ->sum('units');

        return $this->status($period, $earned);
    }

    /**
     * Compliance status of every active lawyer in a firm for a period.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function firmSummary(int $firmId, McleCompliancePeriod $period): Collection
    {
        $lawyers = User::withoutGlobalScopes()
            ->where('firm_id', $firmId)
            ->where('is_active', true)
            ->get()
            ->filter(fn (User $user) => $user->role->isLawyer());

        $unitsByUser = McleCredit::query()
            ->where('period_id', $period->id)
            ->whereIn('user_id', $lawyers->modelKeys())
            ->groupBy('user_id')
            ->selectRaw('user_id, SUM(units) as total')
            ->pluck('total', 'user_id');

        return $lawyers
            ->map(fn (User $lawyer) => [
                'user_id' => $lawyer->id,
                'name' => $lawyer->name,
                'roll_number' => $lawyer->roll_number,
                ...$this->status($period, (float) ($unitsByUser[$lawyer->id] ?? 0)),
            ])
            ->sortBy('percent')
            ->values();
    }

    private function status(McleCompliancePeriod $period, float $earned): array
    {
        $required = $period->required_units;

        return [
            'period_id' => $period->id,
            'period_name' => $period->name,
            'period_end' => $period->end_date->toDateString(),
            'required_units' => $required,
            'earned_units' => round($earned, 2),
            'remaining_units' => round(max(0, $required - $earned), 2),
            'is_compliant' => $earned >= $required,
            'percent' => $required > 0 ? (int) min(100, floor($earned / $required * 100)) : 100,
        ];
    }
}
