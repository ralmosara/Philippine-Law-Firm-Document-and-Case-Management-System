<?php

namespace App\Domain\Billing\Statements;

use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\InvoicePayment;
use App\Domain\Matters\Models\Client;
use App\Domain\Matters\Models\Firm;
use App\Domain\Trust\Models\TrustAccount;
use Carbon\CarbonImmutable;

/**
 * A client's statement of account as of a date: every invoice still owed,
 * aged from its due date, what was received in the period, and the funds
 * held in trust for the client with their movements. What Philippine firms
 * send their clients every month.
 */
class StatementOfAccount
{
    /** Aging buckets, by days past the due date. */
    public const BUCKETS = ['current' => 'Not yet due', '1_30' => '1–30 days', '31_60' => '31–60 days', '61_90' => '61–90 days', 'over_90' => 'Over 90 days'];

    /**
     * @return array{firm: Firm, client: Client, as_of: CarbonImmutable, from: CarbonImmutable, invoices: list<array>, aging: array<string, int>, total_due: int, payments: list<array>, received: int, trust: list<array>, trust_total: int}
     */
    public function build(Client $client, ?CarbonImmutable $asOf = null, int $periodDays = 30): array
    {
        $asOf ??= CarbonImmutable::today();
        $from = $asOf->subDays($periodDays);

        $open = Invoice::query()
            ->where('client_id', $client->id)
            ->whereIn('status', InvoiceStatus::receivableValues())
            ->with('matter:id,reference,title')
            ->orderBy('due_at')
            ->get();

        $aging = array_fill_keys(array_keys(self::BUCKETS), 0);
        $invoices = $open->map(function (Invoice $invoice) use ($asOf, &$aging) {
            $balance = $invoice->balanceDue();
            $overdue = $invoice->due_at && $invoice->due_at->lt($asOf) ? (int) $invoice->due_at->diffInDays($asOf) : 0;
            $bucket = match (true) {
                $overdue <= 0 => 'current',
                $overdue <= 30 => '1_30',
                $overdue <= 60 => '31_60',
                $overdue <= 90 => '61_90',
                default => 'over_90',
            };
            $aging[$bucket] += $balance;

            return [
                'number' => $invoice->number,
                'matter' => $invoice->matter ? "{$invoice->matter->reference} {$invoice->matter->title}" : null,
                'issued_at' => $invoice->issued_at,
                'due_at' => $invoice->due_at,
                'total' => $invoice->total_cents,
                'paid' => $invoice->total_cents - $balance,
                'balance' => $balance,
                'days_overdue' => $overdue,
                'bucket' => $bucket,
            ];
        })->values()->all();

        $payments = InvoicePayment::query()
            ->active()
            ->whereHas('invoice', fn ($q) => $q->where('client_id', $client->id))
            ->whereBetween('received_on', [$from->toDateString(), $asOf->toDateString()])
            ->with('invoice:id,number')
            ->orderBy('received_on')
            ->get()
            ->map(fn (InvoicePayment $p) => [
                'date' => $p->received_on,
                'invoice' => $p->invoice?->number,
                'amount' => $p->amount_cents,
                'withheld' => $p->withholding_cents,
                'reference' => $p->reference,
            ])->values()->all();

        $trust = TrustAccount::query()
            ->where('client_id', $client->id)
            ->where(fn ($q) => $q->where('status', 'open')->orWhere('balance_cents', '>', 0))
            ->with(['matter:id,reference,title', 'transactions' => fn ($q) => $q->whereBetween('created_at', [$from->startOfDay(), $asOf->endOfDay()])->reorder('id')])
            ->get()
            ->map(fn (TrustAccount $a) => [
                'account' => $a->account_number,
                'matter' => $a->matter ? "{$a->matter->reference} {$a->matter->title}" : null,
                'balance' => $a->balance_cents,
                'transactions' => $a->transactions->map(fn ($t) => [
                    'date' => $t->created_at,
                    'type' => $t->type->value,
                    'description' => $t->description,
                    'amount' => $t->amount_cents,
                    'balance_after' => $t->balance_after_cents,
                ])->values()->all(),
            ])->values()->all();

        return [
            'firm' => Firm::findOrFail($client->firm_id),
            'client' => $client,
            'as_of' => $asOf,
            'from' => $from,
            'invoices' => $invoices,
            'aging' => $aging,
            'total_due' => array_sum($aging),
            'payments' => $payments,
            'received' => (int) collect($payments)->sum(fn ($p) => $p['amount'] + $p['withheld']),
            'trust' => $trust,
            'trust_total' => (int) collect($trust)->sum('balance'),
        ];
    }

    /** Worth sending: something owed, or funds held in trust. */
    public function hasContent(array $statement): bool
    {
        return $statement['total_due'] > 0 || $statement['trust'] !== [];
    }
}
