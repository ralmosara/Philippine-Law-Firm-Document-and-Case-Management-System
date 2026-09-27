<?php

namespace App\Domain\Billing\Services;

use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Models\Expense;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\TimeEntry;
use App\Domain\Matters\Models\Matter;
use App\Domain\Trust\Models\TrustAccount;
use App\Domain\Trust\Services\TrustLedgerService;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Turns unbilled time into invoices and moves invoices through their
 * lifecycle. All amounts are integer centavos.
 */
class InvoiceGenerator
{
    /** Philippine VAT on professional services (NIRC Sec. 108). */
    public const VAT_RATE_BASIS_POINTS = 1200;

    public function __construct(private readonly TrustLedgerService $trust) {}

    /**
     * Draft an invoice for a matter from any mix of:
     * - unbilled time entries (null = all of them, [] = none),
     * - unbilled billable expenses (null = all, [] = none), and
     * - fee lines for non-hourly arrangements (acceptance fee, flat-fee
     *   installment, monthly retainer, appearance fee, contingency share).
     *
     * Time and fee lines are professional fees and carry VAT for a
     * VAT-registered firm; expenses are reimbursed at cost, outside the VAT
     * base. Claimed entries are locked so nothing is billed twice.
     *
     * @param  list<int>|null  $timeEntryIds
     * @param  list<int>|null  $expenseIds
     * @param  list<array{description: string, amount_cents: int}>  $feeLines
     */
    public function generateForMatter(
        Matter $matter,
        User $by,
        ?array $timeEntryIds = null,
        int $dueInDays = 30,
        ?string $notes = null,
        ?array $expenseIds = null,
        array $feeLines = [],
    ): Invoice {
        return DB::transaction(function () use ($matter, $by, $timeEntryIds, $dueInDays, $notes, $expenseIds, $feeLines) {
            $entries = $timeEntryIds === [] ? new EloquentCollection : TimeEntry::query()
                ->where('matter_id', $matter->id)
                ->unbilled()
                ->when($timeEntryIds !== null, fn ($q) => $q->whereIn('id', $timeEntryIds))
                ->with('user:id,name')
                ->orderBy('work_date')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $expenses = $expenseIds === [] ? new EloquentCollection : Expense::query()
                ->where('matter_id', $matter->id)
                ->unbilled()
                ->when($expenseIds !== null, fn ($q) => $q->whereIn('id', $expenseIds))
                ->orderBy('expense_date')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($entries->isEmpty() && $expenses->isEmpty() && $feeLines === []) {
                throw ValidationException::withMessages([
                    'time_entry_ids' => 'There is nothing unbilled to invoice for this matter: no time, expenses or fees.',
                ]);
            }

            $fees = $this->sum([...$entries->pluck('amount_cents'), ...array_column($feeLines, 'amount_cents')]);
            $costs = $this->sum($expenses->pluck('amount_cents')->all());
            $vat = $matter->loadMissing('firm')->firm->vat_registered ? $this->vatOn($fees) : 0;

            $invoice = Invoice::create([
                'firm_id' => $matter->firm_id,
                'client_id' => $matter->client_id,
                'matter_id' => $matter->id,
                'number' => Invoice::nextNumber($matter->firm_id, now()->year),
                'subtotal_cents' => $fees,
                'vat_cents' => $vat,
                'expenses_cents' => $costs,
                'total_cents' => $fees + $vat + $costs,
                'due_at' => now()->addDays($dueInDays),
                'notes' => $notes,
                'created_by' => $by->id,
            ]);

            foreach ($feeLines as $line) {
                $invoice->lines()->create([
                    'kind' => 'fee',
                    'work_date' => now()->toDateString(),
                    'description' => $line['description'],
                    'amount_cents' => $line['amount_cents'],
                ]);
            }

            foreach ($entries as $entry) {
                $invoice->lines()->create([
                    'kind' => 'time',
                    'time_entry_id' => $entry->id,
                    'work_date' => $entry->work_date,
                    'description' => "{$entry->user?->name}: {$entry->description}",
                    'minutes' => $entry->minutes,
                    'rate_cents' => $entry->rate_cents,
                    'amount_cents' => $entry->amount_cents,
                ]);
            }

            foreach ($expenses as $expense) {
                $invoice->lines()->create([
                    'kind' => 'expense',
                    'expense_id' => $expense->id,
                    'work_date' => $expense->expense_date,
                    'description' => "{$expense->category->label()}: {$expense->description}",
                    'amount_cents' => $expense->amount_cents,
                ]);
            }

            TimeEntry::whereKey($entries->modelKeys())->update(['invoice_id' => $invoice->id]);
            Expense::whereKey($expenses->modelKeys())->update(['invoice_id' => $invoice->id]);

            return $invoice->load('lines');
        });
    }

    /**
     * Sum centavo amounts, refusing totals beyond what an integer holds on
     * this platform rather than silently wrapping.
     *
     * @param  iterable<int|string>  $amounts
     */
    private function sum(iterable $amounts): int
    {
        $total = 0;
        foreach ($amounts as $amount) {
            if ((int) $amount > PHP_INT_MAX - $total) {
                throw ValidationException::withMessages(['amount_cents' => 'The invoice total is too large.']);
            }
            $total += (int) $amount;
        }

        return $total;
    }

    /**
     * VAT rounded half-up to the centavo. Split into whole and remainder
     * parts so the intermediate product never overflows an integer.
     */
    public function vatOn(int $subtotalCents): int
    {
        $whole = intdiv($subtotalCents, 10000) * self::VAT_RATE_BASIS_POINTS;
        $remainder = $subtotalCents % 10000;

        return $whole + intdiv($remainder * self::VAT_RATE_BASIS_POINTS + 5000, 10000);
    }

    public function issue(Invoice $invoice): Invoice
    {
        $this->transition($invoice, InvoiceStatus::Issued);
        $invoice->forceFill(['issued_at' => now()])->save();

        return $invoice;
    }

    /**
     * Record payment. When a trust account is given, the invoice total is
     * disbursed from client trust funds in the same transaction, so the
     * ledger and the invoice can never disagree.
     */
    public function markPaid(Invoice $invoice, ?User $by, ?string $reference = null, ?TrustAccount $fromTrust = null): Invoice
    {
        return DB::transaction(function () use ($invoice, $by, $reference, $fromTrust) {
            $this->transition($invoice, InvoiceStatus::Paid);

            if ($fromTrust !== null) {
                if ($fromTrust->client_id !== $invoice->client_id) {
                    throw ValidationException::withMessages([
                        'trust_account_id' => 'Trust funds can only be applied to the same client\'s invoices.',
                    ]);
                }

                $transaction = $this->trust->disburse($fromTrust, $invoice->total_cents, "Payment of invoice {$invoice->number}", $invoice->number, $by);
                $reference ??= "TRUST-TX-{$transaction->id}";
            }

            $invoice->forceFill(['paid_at' => now(), 'payment_reference' => $reference])->save();

            return $invoice;
        });
    }

    /** Void an invoice and release its time entries for re-billing. */
    public function void(Invoice $invoice): Invoice
    {
        return DB::transaction(function () use ($invoice) {
            $this->transition($invoice, InvoiceStatus::Void);
            TimeEntry::where('invoice_id', $invoice->id)->update(['invoice_id' => null]);
            Expense::where('invoice_id', $invoice->id)->update(['invoice_id' => null]);

            return $invoice;
        });
    }

    private function transition(Invoice $invoice, InvoiceStatus $to): void
    {
        if (! $invoice->status->canTransitionTo($to)) {
            throw ValidationException::withMessages([
                'status' => "A {$invoice->status->value} invoice cannot be marked {$to->value}.",
            ]);
        }

        $invoice->forceFill(['status' => $to])->save();
    }
}
