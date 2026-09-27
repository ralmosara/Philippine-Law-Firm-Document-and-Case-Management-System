<?php

namespace App\Domain\Billing\Services;

use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\InvoicePayment;
use App\Domain\Trust\Models\TrustAccount;
use App\Domain\Trust\Services\TrustLedgerService;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Payments against invoices, full or partial, with creditable withholding
 * tax. Keeps the invoice's settled amount and status (issued, partially
 * paid, paid) in step with the payments, under a row lock.
 */
class InvoicePayments
{
    public function __construct(private readonly TrustLedgerService $trust) {}

    /**
     * @param  array{received_on?: string|null, method: string, amount_cents: int, withholding_cents?: int, reference?: string|null, notes?: string|null, form_2307_received?: bool, form_2307_file_id?: int|null, online_payment_id?: int|null}  $data
     */
    public function record(Invoice $invoice, array $data, ?User $by, ?TrustAccount $fromTrust = null): InvoicePayment
    {
        return DB::transaction(function () use ($invoice, $data, $by, $fromTrust) {
            $invoice = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            if (! $invoice->status->isReceivable()) {
                throw ValidationException::withMessages(['amount_cents' => "Payments can only be recorded on issued invoices; this one is {$invoice->status->value}."]);
            }

            $amount = (int) $data['amount_cents'];
            $withholding = (int) ($data['withholding_cents'] ?? 0);

            if ($amount < 0 || $withholding < 0 || $amount + $withholding <= 0) {
                throw ValidationException::withMessages(['amount_cents' => 'Enter the amount received or the tax withheld.']);
            }
            if ($amount + $withholding > $invoice->balanceDue()) {
                throw ValidationException::withMessages(['amount_cents' => 'This is more than the balance of '.$this->peso($invoice->balanceDue()).'.']);
            }
            if ($withholding > $invoice->withholdingRoom()) {
                throw ValidationException::withMessages(['withholding_cents' => 'Tax withheld cannot exceed the professional fees (before VAT) still open: '.$this->peso($invoice->withholdingRoom()).'.']);
            }

            $trustTransaction = null;
            if ($fromTrust !== null) {
                if ((int) $fromTrust->client_id !== (int) $invoice->client_id) {
                    throw ValidationException::withMessages(['trust_account_id' => 'Trust funds can only be applied to the same client\'s invoices.']);
                }
                $trustTransaction = $this->trust->disburse($fromTrust, $amount, "Payment of invoice {$invoice->number}", $invoice->number, $by);
            }

            $payment = InvoicePayment::create([
                'firm_id' => $invoice->firm_id,
                'invoice_id' => $invoice->id,
                'received_on' => $data['received_on'] ?? now()->toDateString(),
                'method' => $fromTrust ? 'trust' : $data['method'],
                'amount_cents' => $amount,
                'withholding_cents' => $withholding,
                'reference' => $data['reference'] ?? ($trustTransaction ? "TRUST-TX-{$trustTransaction->id}" : null),
                'notes' => $data['notes'] ?? null,
                'form_2307_received_at' => ! empty($data['form_2307_received']) && $withholding > 0 ? now() : null,
                'form_2307_file_id' => $withholding > 0 ? ($data['form_2307_file_id'] ?? null) : null,
                'trust_transaction_id' => $trustTransaction?->id,
                'online_payment_id' => $data['online_payment_id'] ?? null,
                'recorded_by' => $by?->id,
            ]);

            $this->sync($invoice);

            return $payment;
        });
    }

    /** Pay whatever is still open in one go (e.g. from trust, or a full bank transfer). */
    public function settleInFull(Invoice $invoice, ?User $by, ?string $reference = null, ?TrustAccount $fromTrust = null, string $method = 'other'): InvoicePayment
    {
        return $this->record($invoice, ['method' => $method, 'amount_cents' => $invoice->fresh()->balanceDue(), 'reference' => $reference], $by, $fromTrust);
    }

    /** Reverse a mistaken entry. Money taken from trust goes back to trust. */
    public function void(InvoicePayment $payment, string $reason, User $by): InvoicePayment
    {
        return DB::transaction(function () use ($payment, $reason, $by) {
            $payment = InvoicePayment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $invoice = Invoice::whereKey($payment->invoice_id)->lockForUpdate()->firstOrFail();

            if ($payment->isVoided()) {
                throw ValidationException::withMessages(['payment' => 'This payment is already voided.']);
            }
            if ($invoice->status === InvoiceStatus::Void) {
                throw ValidationException::withMessages(['payment' => 'The invoice is void.']);
            }

            if ($payment->trust_transaction_id !== null) {
                $account = TrustAccount::findOrFail($this->trustAccountId($payment));
                $this->trust->deposit($account, $payment->amount_cents, "Reversal of payment on invoice {$invoice->number}", "VOID-PAY-{$payment->id}", $by);
            }

            $payment->forceFill(['voided_at' => now(), 'voided_by' => $by->id, 'void_reason' => $reason])->save();
            $this->sync($invoice);

            return $payment;
        });
    }

    public function mark2307Received(InvoicePayment $payment, ?int $fileId): InvoicePayment
    {
        if ($payment->isVoided() || $payment->withholding_cents === 0) {
            throw ValidationException::withMessages(['payment' => 'Only active payments with tax withheld need a Form 2307.']);
        }

        $payment->forceFill(['form_2307_received_at' => now(), 'form_2307_file_id' => $fileId ?? $payment->form_2307_file_id])->save();

        return $payment;
    }

    /** Recompute the invoice's settled amounts and status from its active payments. */
    private function sync(Invoice $invoice): void
    {
        $active = InvoicePayment::where('invoice_id', $invoice->id)->active();
        $settled = (int) (clone $active)->sum(DB::raw('amount_cents + withholding_cents'));
        $withheld = (int) (clone $active)->sum('withholding_cents');
        $lastPaidOn = (clone $active)->max('received_on');

        $status = match (true) {
            $settled >= $invoice->total_cents => InvoiceStatus::Paid,
            $settled > 0 => InvoiceStatus::PartiallyPaid,
            default => InvoiceStatus::Issued,
        };

        $invoice->forceFill([
            'settled_cents' => $settled,
            'withholding_cents' => $withheld,
            'status' => $status,
            'paid_at' => $status === InvoiceStatus::Paid ? CarbonImmutable::parse((string) $lastPaidOn) : null,
            'payment_reference' => $status === InvoiceStatus::Paid
                ? InvoicePayment::where('invoice_id', $invoice->id)->active()->latest('id')->value('reference')
                : null,
        ])->save();
    }

    private function trustAccountId(InvoicePayment $payment): int
    {
        return (int) DB::table('trust_transactions')->where('id', $payment->trust_transaction_id)->value('trust_account_id');
    }

    private function peso(int $cents): string
    {
        return '₱'.number_format($cents / 100, 2);
    }
}
