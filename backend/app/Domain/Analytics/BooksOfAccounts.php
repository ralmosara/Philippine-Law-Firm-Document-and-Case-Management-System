<?php

namespace App\Domain\Analytics;

use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Models\DisbursementRequest;
use App\Domain\Billing\Models\Expense;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\InvoicePayment;
use App\Domain\Billing\Models\Payment;
use App\Domain\Trust\Enums\TrustTransactionType;
use App\Domain\Trust\Models\TrustAccount;
use App\Domain\Trust\Models\TrustTransaction;
use Carbon\CarbonImmutable;

/**
 * The month's transactions in the shape of the books a Philippine firm
 * keeps for the BIR, for the accountant to post or print on loose leaf:
 * cash receipts, cash disbursements and a general journal for the firm's
 * own money, and a separate book for client funds held in trust (which are
 * never the firm's income). Amounts are centavos.
 *
 * The account titles are suggestions to map to the firm's chart of
 * accounts; the accountant decides the final entries.
 */
class BooksOfAccounts
{
    public const BOOKS = [
        'cash-receipts' => 'Cash receipts journal',
        'cash-disbursements' => 'Cash disbursements journal',
        'general-journal' => 'General journal',
        'trust' => 'Client trust funds book',
    ];

    /** @return array{title: string, columns: array<string, string>, money_columns: list<string>, rows: list<array>, totals: array<string, int>} */
    public function book(string $book, CarbonImmutable $month): array
    {
        $from = $month->startOfMonth();
        $to = $month->endOfMonth();

        return match ($book) {
            'cash-receipts' => $this->cashReceipts($from, $to),
            'cash-disbursements' => $this->cashDisbursements($from, $to),
            'general-journal' => $this->generalJournal($from, $to),
            'trust' => $this->trust($from, $to),
        };
    }

    private function cashReceipts(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = InvoicePayment::query()->active()
            ->whereBetween('received_on', [$from->toDateString(), $to->toDateString()])
            ->with('invoice:id,number,client_id', 'invoice.client:id,name,tin')
            ->orderBy('received_on')->orderBy('id')->get()
            ->map(fn (InvoicePayment $p) => [
                'date' => $p->received_on->toDateString(),
                'reference' => $p->reference ?? "PAY-{$p->id}",
                'client' => $p->invoice?->client?->name,
                'tin' => $p->invoice?->client?->tin,
                'invoice' => $p->invoice?->number,
                'method' => $p->method,
                // Dr Cash in bank, Dr Creditable withholding tax, Cr Accounts receivable.
                'cash' => $p->amount_cents,
                'cwt' => $p->withholding_cents,
                'receivable' => $p->amount_cents + $p->withholding_cents,
            ])->values()->all();

        return $this->result('cash-receipts', ['date' => 'Date', 'reference' => 'Reference', 'client' => 'Received from', 'tin' => 'TIN', 'invoice' => 'Billing statement', 'method' => 'Method', 'cash' => 'Dr Cash', 'cwt' => 'Dr Creditable WT', 'receivable' => 'Cr Accounts receivable'], ['cash', 'cwt', 'receivable'], $rows);
    }

    private function cashDisbursements(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = collect();

        // Costs the firm paid directly for clients (not from a cash advance).
        Expense::query()->whereNull('disbursement_request_id')->whereBetween('expense_date', [$from->toDateString(), $to->toDateString()])
            ->with('matter:id,reference')->get()
            ->each(fn (Expense $e) => $rows->push([
                'date' => $e->expense_date->toDateString(),
                'reference' => "EXP-{$e->id}",
                'particulars' => "{$e->category->label()}: {$e->description}",
                'matter' => $e->matter?->reference,
                'account' => $e->is_billable ? 'Advances for clients (reimbursable)' : 'Litigation expense (not billed)',
                'amount' => $e->amount_cents,
            ]));

        // Cash advances released from the firm's own funds.
        DisbursementRequest::query()->where('source', DisbursementRequest::FROM_FIRM)->whereBetween('released_at', [$from->startOfDay(), $to->endOfDay()])
            ->with('matter:id,reference', 'requester:id,name')->get()
            ->each(fn (DisbursementRequest $d) => $rows->push([
                'date' => $d->released_at->timezone(config('app.timezone'))->toDateString(),
                'reference' => "CA-{$d->id}",
                'particulars' => 'Cash advance to '.($d->requester?->name ?? 'staff').": {$d->description}",
                'matter' => $d->matter?->reference,
                'account' => 'Advances to officers and employees',
                'amount' => $d->amount_cents,
            ]));

        // Online payments refunded to clients.
        Payment::query()->where('refund_status', 'succeeded')->whereBetween('refunded_at', [$from->startOfDay(), $to->endOfDay()])
            ->with('invoice:id,number')->get()
            ->each(fn (Payment $p) => $rows->push([
                'date' => $p->refunded_at->timezone(config('app.timezone'))->toDateString(),
                'reference' => $p->refund_id,
                'particulars' => 'Refund of an online payment'.($p->invoice ? " on {$p->invoice->number}" : ''),
                'matter' => null,
                'account' => 'Refunds payable / Accounts receivable',
                'amount' => $p->amount_cents,
            ]));

        return $this->result('cash-disbursements', ['date' => 'Date', 'reference' => 'Voucher / reference', 'particulars' => 'Particulars', 'matter' => 'Matter', 'account' => 'Dr Account', 'amount' => 'Cr Cash'], ['amount'], $rows->sortBy('date')->values()->all());
    }

    private function generalJournal(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = [];
        $line = fn (string $date, string $ref, string $account, int $debit, int $credit, string $particulars) => ['date' => $date, 'reference' => $ref, 'account' => $account, 'debit' => $debit, 'credit' => $credit, 'particulars' => $particulars];

        // Bills issued: Dr Accounts receivable, Cr Professional fees, Output VAT, Reimbursable expenses.
        $issued = Invoice::query()->whereNotIn('status', [InvoiceStatus::Draft->value])
            ->whereBetween('issued_at', [$from->toDateString(), $to->toDateString()])
            ->with('client:id,name')->orderBy('issued_at')->orderBy('id')->get();
        foreach ($issued as $i) {
            $d = $i->issued_at->toDateString();
            $who = "{$i->client?->name}".($i->status === InvoiceStatus::Void ? ' (voided)' : '');
            $rows[] = $line($d, $i->number, 'Accounts receivable', $i->total_cents, 0, "Billing statement to {$who}");
            $rows[] = $line($d, $i->number, 'Professional fees', 0, $i->subtotal_cents, '');
            if ($i->vat_cents) {
                $rows[] = $line($d, $i->number, 'Output VAT', 0, $i->vat_cents, '');
            }
            if ($i->expenses_cents) {
                $rows[] = $line($d, $i->number, 'Advances for clients (reimbursable)', 0, $i->expenses_cents, '');
            }
        }

        // Balances written off: Dr Bad debts, Cr Accounts receivable.
        Invoice::query()->where('status', InvoiceStatus::WrittenOff->value)->whereBetween('written_off_at', [$from->startOfDay(), $to->endOfDay()])
            ->with('client:id,name')->get()
            ->each(function (Invoice $i) use (&$rows, $line) {
                $d = $i->written_off_at->timezone(config('app.timezone'))->toDateString();
                $rows[] = $line($d, $i->number, 'Bad debts', $i->written_off_cents, 0, "Written off: {$i->client?->name}. {$i->write_off_reason}");
                $rows[] = $line($d, $i->number, 'Accounts receivable', 0, $i->written_off_cents, '');
            });

        // Firm cash advances liquidated: spent on clients' costs, the rest returned.
        DisbursementRequest::query()->where('source', DisbursementRequest::FROM_FIRM)->whereBetween('liquidated_at', [$from->startOfDay(), $to->endOfDay()])
            ->get()
            ->each(function (DisbursementRequest $r) use (&$rows, $line) {
                $d = $r->liquidated_at->timezone(config('app.timezone'))->toDateString();
                $ref = "CA-{$r->id}";
                if ($r->spent_cents) {
                    $rows[] = $line($d, $ref, 'Advances for clients (reimbursable)', (int) $r->spent_cents, 0, "Liquidation of cash advance: {$r->description}");
                }
                if ($r->returned_cents) {
                    $rows[] = $line($d, $ref, 'Cash on hand', (int) $r->returned_cents, 0, 'Unspent balance returned');
                }
                $rows[] = $line($d, $ref, 'Advances to officers and employees', 0, (int) $r->spent_cents + (int) $r->returned_cents, '');
            });

        usort($rows, fn ($a, $b) => strcmp($a['date'], $b['date']));

        return $this->result('general-journal', ['date' => 'Date', 'reference' => 'Reference', 'account' => 'Account', 'debit' => 'Debit', 'credit' => 'Credit', 'particulars' => 'Particulars'], ['debit', 'credit'], $rows);
    }

    private function trust(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $accounts = TrustAccount::query()->with('client:id,name', 'matter:id,reference')->get()->keyBy('id');
        $rows = TrustTransaction::query()->whereIn('trust_account_id', $accounts->keys())
            ->whereBetween('created_at', [$from->startOfDay(), $to->endOfDay()])
            ->orderBy('created_at')->orderBy('id')->get()
            ->map(function (TrustTransaction $t) use ($accounts) {
                $a = $accounts[$t->trust_account_id];

                return [
                    'date' => $t->created_at->timezone(config('app.timezone'))->toDateString(),
                    'reference' => $t->reference ?? "TX-{$t->id}",
                    'account' => $a->account_number,
                    'client' => $a->client?->name,
                    'matter' => $a->matter?->reference,
                    'particulars' => $t->description,
                    'receipts' => $t->type === TrustTransactionType::Deposit ? $t->amount_cents : 0,
                    'disbursements' => $t->type === TrustTransactionType::Deposit ? 0 : $t->amount_cents,
                    'balance' => $t->balance_after_cents,
                ];
            })->values()->all();

        $result = $this->result('trust', ['date' => 'Date', 'reference' => 'Reference', 'account' => 'Trust account', 'client' => 'Client', 'matter' => 'Matter', 'particulars' => 'Particulars', 'receipts' => 'Receipts', 'disbursements' => 'Disbursements', 'balance' => 'Account balance'], ['receipts', 'disbursements'], $rows);
        unset($result['totals']['balance']);

        return $result;
    }

    private function result(string $book, array $columns, array $money, array $rows): array
    {
        $totals = [];
        foreach ($money as $key) {
            $totals[$key] = (int) array_sum(array_column($rows, $key));
        }

        return ['title' => self::BOOKS[$book], 'columns' => $columns, 'money_columns' => array_values(array_unique([...$money, ...(array_key_exists('balance', $columns) ? ['balance'] : [])])), 'rows' => $rows, 'totals' => $totals];
    }
}
