<?php

namespace App\Domain\Billing\Services;

use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\InvoiceLine;
use App\Domain\Matters\Models\Firm;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * What the firm chooses not to charge. Before a bill goes out, a line can
 * be written down and a discount given on the professional fees (VAT is
 * then computed on what is actually charged). After, a balance that will
 * not be collected is written off, which takes it out of receivables,
 * reminders and statements; a write-off can be undone if the client pays.
 * Each one keeps who did it and why.
 */
class InvoiceAdjustments
{
    public function __construct(private readonly InvoiceGenerator $generator) {}

    /** Reduce a draft line to $amount (its original amount restores it). */
    public function writeDown(InvoiceLine $line, int $amount, ?string $reason, User $by): Invoice
    {
        return DB::transaction(function () use ($line, $amount, $reason, $by) {
            $invoice = $this->lockDraft($line->invoice_id);
            $line = InvoiceLine::whereKey($line->id)->lockForUpdate()->firstOrFail();
            $original = $line->original_amount_cents ?? $line->amount_cents;

            if ($amount < 0 || $amount > $original) {
                throw ValidationException::withMessages(['amount_cents' => 'Enter an amount from ₱0.00 up to the original '.$this->peso($original).'.']);
            }
            $restored = $amount === $original;
            if (! $restored && blank($reason)) {
                throw ValidationException::withMessages(['reason' => 'Say why it is written down (kept on file, not shown to the client).']);
            }

            $line->forceFill([
                'amount_cents' => $amount,
                'original_amount_cents' => $restored ? null : $original,
                'adjustment_reason' => $restored ? null : $reason,
            ])->save();
            $this->recalculate($invoice);
            AuditLog::record('invoice_line_written_down', $invoice->firm_id, $by, $invoice, ['line' => $line->id, 'from' => $original, 'to' => $amount, 'reason' => $reason]);

            return $invoice->refresh();
        });
    }

    /** A discount off a draft's professional fees, before VAT. 0 removes it. */
    public function discount(Invoice $invoice, int $amount, ?string $reason, User $by): Invoice
    {
        return DB::transaction(function () use ($invoice, $amount, $reason, $by) {
            $invoice = $this->lockDraft($invoice->id);
            $fees = (int) $invoice->lines()->where('kind', '!=', 'expense')->sum('amount_cents');

            if ($amount < 0 || $amount > $fees) {
                throw ValidationException::withMessages(['discount_cents' => 'A discount can be up to the professional fees, '.$this->peso($fees).'.']);
            }
            if ($amount > 0 && blank($reason)) {
                throw ValidationException::withMessages(['discount_reason' => 'Say what the discount is for; it is shown on the bill.']);
            }

            $invoice->forceFill(['discount_cents' => $amount, 'discount_reason' => $amount > 0 ? $reason : null])->save();
            $this->recalculate($invoice);
            AuditLog::record('invoice_discounted', $invoice->firm_id, $by, $invoice, ['amount' => $amount, 'reason' => $reason]);

            return $invoice->refresh();
        });
    }

    /** Write off what is left of an issued bill. */
    public function writeOff(Invoice $invoice, string $reason, User $by): Invoice
    {
        return DB::transaction(function () use ($invoice, $reason, $by) {
            $invoice = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if (! $invoice->status->canTransitionTo(InvoiceStatus::WrittenOff) || $invoice->balanceDue() === 0) {
                throw ValidationException::withMessages(['status' => 'Only an issued bill with a balance can be written off.']);
            }

            $amount = $invoice->balanceDue();
            $invoice->forceFill([
                'status' => InvoiceStatus::WrittenOff,
                'written_off_cents' => $amount,
                'written_off_at' => now(),
                'written_off_by' => $by->id,
                'write_off_reason' => $reason,
            ])->save();
            AuditLog::record('invoice_written_off', $invoice->firm_id, $by, $invoice, ['amount' => $amount, 'reason' => $reason]);

            return $invoice;
        });
    }

    /** The client paid after all: the balance is owed again. */
    public function undoWriteOff(Invoice $invoice, User $by): Invoice
    {
        return DB::transaction(function () use ($invoice, $by) {
            $invoice = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if ($invoice->status !== InvoiceStatus::WrittenOff) {
                throw ValidationException::withMessages(['status' => 'This bill is not written off.']);
            }

            $amount = $invoice->written_off_cents;
            $invoice->forceFill([
                'status' => $invoice->settled_cents > 0 ? InvoiceStatus::PartiallyPaid : InvoiceStatus::Issued,
                'written_off_cents' => 0,
                'written_off_at' => null,
                'written_off_by' => null,
                'write_off_reason' => null,
            ])->save();
            AuditLog::record('invoice_write_off_undone', $invoice->firm_id, $by, $invoice, ['amount' => $amount]);

            return $invoice;
        });
    }

    /** Totals from the lines: fees less the discount, VAT on that, expenses at cost. */
    public function recalculate(Invoice $invoice): void
    {
        $lines = $invoice->lines()->get(['kind', 'amount_cents']);
        $fees = max(0, (int) $lines->where('kind', '!=', 'expense')->sum('amount_cents') - $invoice->discount_cents);
        $costs = (int) $lines->where('kind', 'expense')->sum('amount_cents');
        $vat = Firm::findOrFail($invoice->firm_id)->vat_registered ? $this->generator->vatOn($fees) : 0;

        $invoice->forceFill(['subtotal_cents' => $fees, 'vat_cents' => $vat, 'expenses_cents' => $costs, 'total_cents' => $fees + $vat + $costs])->save();
    }

    private function lockDraft(int $invoiceId): Invoice
    {
        $invoice = Invoice::whereKey($invoiceId)->lockForUpdate()->firstOrFail();
        if ($invoice->status !== InvoiceStatus::Draft) {
            throw ValidationException::withMessages(['status' => 'Only a draft can be adjusted. Once issued, write off what will not be collected.']);
        }

        return $invoice;
    }

    private function peso(int $cents): string
    {
        return '₱'.number_format($cents / 100, 2);
    }
}
