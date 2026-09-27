<?php

namespace App\Domain\Analytics;

use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Models\Expense;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\TimeEntry;
use App\Domain\Matters\Models\Matter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Financial reports for firm management. Every query runs inside the tenant
 * scope, so a report only ever covers the signed-in user's firm.
 * Amounts are integer centavos.
 */
class Reports
{
    public const BUCKETS = ['current' => 'Not yet due', 'd1_30' => '1–30 days', 'd31_60' => '31–60 days', 'd61_90' => '61–90 days', 'd90_plus' => 'Over 90 days'];

    /**
     * Unpaid issued invoices by client, aged by days past due.
     *
     * @return array{as_of: string, rows: list<array>, totals: array<string, int>}
     */
    public function agedReceivables(CarbonImmutable $asOf): array
    {
        $invoices = Invoice::query()
            ->where('status', InvoiceStatus::Issued->value)
            ->whereDate('issued_at', '<=', $asOf->toDateString())
            ->with('client:id,name')
            ->get(['id', 'client_id', 'number', 'due_at', 'total_cents']);

        $rows = $invoices->groupBy('client_id')->map(function (Collection $group) use ($asOf) {
            $row = ['client' => $group->first()->client?->name, 'invoices' => $group->count()] + array_fill_keys(array_keys(self::BUCKETS), 0);

            foreach ($group as $invoice) {
                $row[$this->bucket($invoice->due_at, $asOf)] += $invoice->total_cents;
            }
            $row['total'] = array_sum(array_intersect_key($row, self::BUCKETS));

            return $row;
        })->sortByDesc('total')->values()->all();

        $totals = array_fill_keys([...array_keys(self::BUCKETS), 'total'], 0);
        foreach ($rows as $row) {
            foreach ($totals as $key => $_) {
                $totals[$key] += $row[$key];
            }
        }

        return ['as_of' => $asOf->toDateString(), 'rows' => $rows, 'totals' => $totals];
    }

    /**
     * Payments received in a period, credited to each matter's responsible lawyer.
     *
     * @return array{from: string, to: string, rows: list<array>, totals: array<string, int>}
     */
    public function collections(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $paid = Invoice::query()
            ->where('status', InvoiceStatus::Paid->value)
            ->whereBetween('paid_at', [$from->startOfDay(), $to->endOfDay()])
            ->with('matter:id,responsible_lawyer_id', 'matter.responsibleLawyer:id,name')
            ->get(['id', 'matter_id', 'subtotal_cents', 'vat_cents', 'expenses_cents', 'total_cents']);

        $rows = $paid->groupBy(fn (Invoice $i) => $i->matter?->responsible_lawyer_id ?? 0)->map(fn (Collection $group) => [
            'lawyer' => $group->first()->matter?->responsibleLawyer?->name ?? 'Unassigned',
            'invoices' => $group->count(),
            'fees' => (int) $group->sum('subtotal_cents'),
            'vat' => (int) $group->sum('vat_cents'),
            'expenses' => (int) $group->sum('expenses_cents'),
            'total' => (int) $group->sum('total_cents'),
        ])->sortByDesc('total')->values()->all();

        $totals = ['invoices' => 0, 'fees' => 0, 'vat' => 0, 'expenses' => 0, 'total' => 0];
        foreach ($rows as $row) {
            foreach ($totals as $key => $_) {
                $totals[$key] += $row[$key];
            }
        }

        return ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'rows' => $rows, 'totals' => $totals];
    }

    /**
     * For each open (or recently active) matter: the value of time recorded,
     * what was billed and collected, work still unbilled, and realization.
     *
     * @return array{rows: list<array>, totals: array<string, int>}
     */
    public function matterProfitability(): array
    {
        $recorded = TimeEntry::query()->selectRaw('matter_id, SUM(amount_cents) as total')->where('is_billable', true)->groupBy('matter_id')->pluck('total', 'matter_id');
        $unbilled = TimeEntry::query()->unbilled()->selectRaw('matter_id, SUM(amount_cents) as total')->groupBy('matter_id')->pluck('total', 'matter_id');
        $expenses = Expense::query()->selectRaw('matter_id, SUM(amount_cents) as total')->groupBy('matter_id')->pluck('total', 'matter_id');
        $billed = Invoice::query()->where('status', '!=', InvoiceStatus::Void->value)->where('status', '!=', InvoiceStatus::Draft->value)
            ->selectRaw('matter_id, SUM(subtotal_cents) as total')->groupBy('matter_id')->pluck('total', 'matter_id');
        $collected = Invoice::query()->where('status', InvoiceStatus::Paid->value)
            ->selectRaw('matter_id, SUM(subtotal_cents) as total')->groupBy('matter_id')->pluck('total', 'matter_id');

        $ids = collect([$recorded, $unbilled, $expenses, $billed, $collected])->flatMap(fn ($c) => $c->keys())->unique();
        $matters = Matter::query()->whereIn('id', $ids)->with('client:id,name', 'responsibleLawyer:id,name')->get(['id', 'reference', 'title', 'client_id', 'responsible_lawyer_id', 'status']);

        $rows = $matters->map(function (Matter $m) use ($recorded, $unbilled, $expenses, $billed, $collected) {
            $row = [
                'reference' => $m->reference,
                'title' => $m->title,
                'client' => $m->client?->name,
                'lawyer' => $m->responsibleLawyer?->name,
                'status' => $m->status->label(),
                'recorded' => (int) ($recorded[$m->id] ?? 0),
                'billed' => (int) ($billed[$m->id] ?? 0),
                'collected' => (int) ($collected[$m->id] ?? 0),
                'unbilled' => (int) ($unbilled[$m->id] ?? 0),
                'expenses' => (int) ($expenses[$m->id] ?? 0),
            ];
            // Collected fees as a share of billed fees.
            $row['collection_rate'] = $row['billed'] > 0 ? round($row['collected'] / $row['billed'] * 100, 1) : null;

            return $row;
        })->sortByDesc('billed')->values()->all();

        $totals = ['recorded' => 0, 'billed' => 0, 'collected' => 0, 'unbilled' => 0, 'expenses' => 0];
        foreach ($rows as $row) {
            foreach ($totals as $key => $_) {
                $totals[$key] += $row[$key];
            }
        }

        return ['rows' => $rows, 'totals' => $totals];
    }

    private function bucket(?\DateTimeInterface $dueAt, CarbonImmutable $asOf): string
    {
        $due = $dueAt ? CarbonImmutable::instance($dueAt)->startOfDay() : null;

        if ($due === null || $due->greaterThanOrEqualTo($asOf->startOfDay())) {
            return 'current';
        }

        $days = (int) $due->diffInDays($asOf->startOfDay());

        return match (true) {
            $days <= 30 => 'd1_30',
            $days <= 60 => 'd31_60',
            $days <= 90 => 'd61_90',
            default => 'd90_plus',
        };
    }
}
