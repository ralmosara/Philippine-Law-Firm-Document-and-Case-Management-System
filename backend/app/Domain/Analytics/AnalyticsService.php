<?php

namespace App\Domain\Analytics;

use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\TimeEntry;
use App\Domain\Deadlines\Enums\DeadlineStatus;
use App\Domain\Deadlines\Models\MatterDeadline;
use App\Domain\Matters\Enums\MatterStatus;
use App\Domain\Matters\Models\Matter;
use App\Domain\Trust\Models\TrustAccount;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Firm performance metrics, computed live from indexed base tables. Every
 * query runs under the tenant scope of the current request.
 */
class AnalyticsService
{
    /** Monthly billable-hour target used for utilization. */
    public const MONTHLY_BILLABLE_TARGET_MINUTES = 120 * 60;

    public function dashboard(?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $monthStart = $today->startOfMonth();
        $yearStart = $today->startOfYear();

        $billedYtd = (int) Invoice::whereIn('status', [InvoiceStatus::Issued->value, InvoiceStatus::Paid->value])
            ->whereDate('issued_at', '>=', $yearStart)->sum('total_cents');
        $collectedYtd = (int) Invoice::where('status', InvoiceStatus::Paid->value)
            ->where('paid_at', '>=', $yearStart)->sum('total_cents');

        return [
            'as_of' => $today->toDateString(),
            'metrics' => [
                'active_matters' => Matter::active()->count(),
                'new_matters_this_month' => Matter::whereDate('opened_at', '>=', $monthStart)->count(),
                'revenue_collected_ytd_cents' => $collectedYtd,
                'billed_ytd_cents' => $billedYtd,
                'collection_rate' => $billedYtd > 0 ? round($collectedYtd / $billedYtd * 100, 1) : null,
                'outstanding_receivables_cents' => (int) Invoice::where('status', InvoiceStatus::Issued->value)->sum('total_cents'),
                'overdue_receivables_cents' => (int) Invoice::where('status', InvoiceStatus::Issued->value)
                    ->whereDate('due_at', '<', $today)->sum('total_cents'),
                'unbilled_wip_cents' => (int) TimeEntry::unbilled()->sum('amount_cents'),
                'trust_funds_held_cents' => (int) TrustAccount::sum('balance_cents'),
                'deadlines_next_7_days' => MatterDeadline::pending()
                    ->whereBetween('due_date', [$today->toDateString(), $today->addDays(7)->toDateString()])->count(),
                'missed_deadlines_30_days' => MatterDeadline::where('status', DeadlineStatus::Missed->value)
                    ->whereDate('due_date', '>=', $today->subDays(30))->count(),
            ],
            'matters_by_status' => $this->mattersByStatus(),
            'revenue_trend' => $this->revenueTrend($today),
            'utilization' => $this->utilization($monthStart, $today),
        ];
    }

    private function mattersByStatus(): array
    {
        $counts = Matter::query()->toBase()
            ->selectRaw('status, COUNT(*) as total')
            ->whereNull('deleted_at')
            ->groupBy('status')
            ->pluck('total', 'status');

        return collect(MatterStatus::cases())
            ->map(fn (MatterStatus $status) => [
                'status' => $status->value,
                'label' => $status->label(),
                'count' => (int) ($counts[$status->value] ?? 0),
            ])
            ->all();
    }

    /**
     * Collected revenue per month for the last six months, oldest first.
     * Bucketed in PHP to stay portable across database drivers.
     */
    private function revenueTrend(CarbonImmutable $today): array
    {
        $start = $today->startOfMonth()->subMonths(5);

        $byMonth = Invoice::where('status', InvoiceStatus::Paid->value)
            ->where('paid_at', '>=', $start)
            ->get(['paid_at', 'total_cents'])
            ->groupBy(fn (Invoice $invoice) => $invoice->paid_at->format('Y-m'))
            ->map->sum('total_cents');

        return collect(range(0, 5))
            ->map(function (int $offset) use ($start, $byMonth) {
                $month = $start->addMonths($offset);

                return [
                    'month' => $month->format('Y-m'),
                    'label' => $month->format('M'),
                    'collected_cents' => (int) ($byMonth[$month->format('Y-m')] ?? 0),
                ];
            })
            ->all();
    }

    /**
     * Billable minutes logged this month per lawyer, against a pro-rated
     * monthly target.
     */
    private function utilization(CarbonImmutable $monthStart, CarbonImmutable $today): array
    {
        $minutesByUser = TimeEntry::query()
            ->where('is_billable', true)
            ->whereBetween('work_date', [$monthStart->toDateString(), $today->toDateString()])
            ->groupBy('user_id')
            ->selectRaw('user_id, SUM(minutes) as total')
            ->pluck('total', 'user_id');

        $monthFraction = $today->day / $today->daysInMonth;
        $targetToDate = max(1, (int) round(self::MONTHLY_BILLABLE_TARGET_MINUTES * $monthFraction));

        return User::where('is_active', true)
            ->orderBy('name')
            ->get()
            ->filter(fn (User $user) => $user->role->isLawyer())
            ->map(fn (User $user) => [
                'user_id' => $user->id,
                'name' => $user->name,
                'billable_minutes' => (int) ($minutesByUser[$user->id] ?? 0),
                'target_minutes_to_date' => $targetToDate,
                'utilization_percent' => (int) round(($minutesByUser[$user->id] ?? 0) / $targetToDate * 100),
            ])
            ->sortByDesc('utilization_percent')
            ->values()
            ->all();
    }
}
