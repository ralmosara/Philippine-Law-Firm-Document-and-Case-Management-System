<?php

namespace App\Domain\Tax;

use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\InvoicePayment;
use App\Domain\Matters\Models\Firm;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * What the firm's own quarterly returns need from its books: sales invoiced
 * (VAT on services follows invoices under the EOPT Act, RA 11976),
 * collections, and the creditable tax clients withheld, listed per client
 * as the Summary Alphalist of Withholding Taxes (SAWT) attached to the
 * income tax return.
 */
class TaxReports
{
    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    public static function quarterRange(int $year, int $quarter): array
    {
        $start = CarbonImmutable::create($year, ($quarter - 1) * 3 + 1, 1)->startOfDay();

        return [$start, $start->addMonths(3)->subDay()];
    }

    public function quarter(int $year, int $quarter): array
    {
        [$from, $to] = self::quarterRange($year, $quarter);

        $invoices = Invoice::query()
            ->whereNotIn('status', [InvoiceStatus::Draft->value, InvoiceStatus::Void->value])
            ->whereBetween('issued_at', [$from->toDateString(), $to->toDateString()])
            ->get(['subtotal_cents', 'vat_cents', 'expenses_cents', 'total_cents']);

        $payments = InvoicePayment::query()->active()
            ->whereBetween('received_on', [$from->toDateString(), $to->toDateString()])
            ->get(['amount_cents', 'withholding_cents', 'form_2307_received_at']);

        $withheld = $payments->where('withholding_cents', '>', 0);

        return [
            'year' => $year,
            'quarter' => $quarter,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'invoiced' => [
                'count' => $invoices->count(),
                'fees_cents' => (int) $invoices->sum('subtotal_cents'),
                'vat_cents' => (int) $invoices->sum('vat_cents'),
                'expenses_cents' => (int) $invoices->sum('expenses_cents'),
                'total_cents' => (int) $invoices->sum('total_cents'),
            ],
            'collected' => [
                'count' => $payments->count(),
                'cash_cents' => (int) $payments->sum('amount_cents'),
                'withheld_cents' => (int) $payments->sum('withholding_cents'),
            ],
            'creditable' => [
                'with_2307_cents' => (int) $withheld->whereNotNull('form_2307_received_at')->sum('withholding_cents'),
                'without_2307_cents' => (int) $withheld->whereNull('form_2307_received_at')->sum('withholding_cents'),
                'without_2307_count' => $withheld->whereNull('form_2307_received_at')->count(),
            ],
        ];
    }

    /**
     * The SAWT for a quarter: one row per client and code, with the income
     * payment (the fee part of what the client settled) and the tax withheld.
     * Only tax backed by a Form 2307 can be claimed; rows without one are
     * flagged. Use the figures on the 2307s themselves when they differ.
     *
     * @return Collection<int, array{payor_tin: ?string, payor_name: string, atc: string, nature: string, income_payment_cents: int, rate: ?float, tax_withheld_cents: int, with_2307: bool, payments: int}>
     */
    public function sawt(Firm $firm, int $year, int $quarter): Collection
    {
        [$from, $to] = self::quarterRange($year, $quarter);

        return InvoicePayment::query()->active()
            ->where('withholding_cents', '>', 0)
            ->whereBetween('received_on', [$from->toDateString(), $to->toDateString()])
            ->with('invoice:id,client_id,subtotal_cents,total_cents', 'invoice.client:id,name,tin')
            ->get()
            ->groupBy(fn (InvoicePayment $p) => $p->invoice?->client_id.'|'.($p->form_2307_received_at ? 1 : 0))
            ->map(function (Collection $group) use ($firm) {
                $client = $group->first()->invoice?->client;
                $income = (int) $group->sum(function (InvoicePayment $p) {
                    $invoice = $p->invoice;

                    return $invoice && $invoice->total_cents > 0
                        ? (int) round($p->creditedCents() * $invoice->subtotal_cents / $invoice->total_cents)
                        : 0;
                });
                $tax = (int) $group->sum('withholding_cents');

                return [
                    'payor_tin' => $client?->tin,
                    'payor_name' => (string) $client?->name,
                    'atc' => $firm->withholding_atc,
                    'nature' => 'Professional fees',
                    'income_payment_cents' => $income,
                    'rate' => $income > 0 ? round($tax / $income * 100, 2) : null,
                    'tax_withheld_cents' => $tax,
                    'with_2307' => $group->first()->form_2307_received_at !== null,
                    'payments' => $group->count(),
                ];
            })
            ->sortBy([['with_2307', 'desc'], ['payor_name', 'asc']])
            ->values();
    }
}
