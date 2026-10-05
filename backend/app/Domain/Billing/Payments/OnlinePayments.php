<?php

namespace App\Domain\Billing\Payments;

use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\Services\InvoicePayments;
use App\Domain\Matters\Models\Matter;
use App\Models\User;
use App\Notifications\OnlinePaymentReceived;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Online payment of issued invoices. The browser is sent to PayMongo's
 * hosted checkout for the balance still open; the payment is recorded only
 * when PayMongo's signed webhook confirms the money, never on the
 * browser's return.
 */
class OnlinePayments
{
    public function __construct(
        private readonly PayMongoGateway $gateway,
        private readonly InvoicePayments $payments,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * Refund an online payment that could not be applied to its invoice (it
     * was paid or voided meanwhile), through PayMongo. Applied payments are
     * not refunded here: the invoice payment would have to be undone too.
     */
    public function refund(Payment $payment, string $reason, ?string $notes, User $by): Payment
    {
        return DB::transaction(function () use ($payment, $reason, $notes, $by) {
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($payment->status !== Payment::UNAPPLIED) {
                throw ValidationException::withMessages(['payment' => 'Only a payment received but not applied to its invoice can be refunded here.']);
            }
            if (in_array($payment->refund_status, ['pending', 'succeeded'], true)) {
                throw ValidationException::withMessages(['payment' => 'This payment has already been refunded.']);
            }
            if (blank($payment->provider_payment_id)) {
                throw ValidationException::withMessages(['payment' => 'PayMongo did not report a payment ID; refund it in the PayMongo dashboard.']);
            }

            try {
                $refund = $this->gateway->refund($payment->provider_payment_id, $payment->amount_cents, $reason, $notes);
            } catch (RequestException $e) {
                $message = (string) data_get($e->response->json(), 'errors.0.detail', 'PayMongo did not accept the refund.');
                throw ValidationException::withMessages(['payment' => "PayMongo: {$message}"]);
            }

            $payment->forceFill([
                'refund_id' => $refund['id'],
                'refund_status' => $refund['status'],
                'refund_reason' => trim($reason.($notes ? ": {$notes}" : '')),
                'refunded_at' => now(),
                'refunded_by' => $by->id,
            ])->save();

            return $payment;
        });
    }

    public function isEnabled(): bool
    {
        return $this->gateway->isEnabled();
    }

    public function canPay(Invoice $invoice): bool
    {
        return $this->isEnabled()
            && $invoice->status->isReceivable()
            && $invoice->balanceDue() >= PayMongoGateway::MINIMUM_CENTS;
    }

    /** Start a checkout and return the URL to send the payer to. */
    public function startCheckout(Invoice $invoice, string $returnUrl): Payment
    {
        if (! $this->isEnabled()) {
            abort(503, __('Online payment is not available. Please contact the firm.'));
        }

        if (! $this->canPay($invoice)) {
            throw ValidationException::withMessages(['invoice' => __('This invoice cannot be paid online.')]);
        }

        $separator = str_contains($returnUrl, '?') ? '&' : '?';

        try {
            $checkout = $this->gateway->createCheckout(
                $invoice->loadMissing('matter'),
                "{$returnUrl}{$separator}payment=success&invoice={$invoice->id}",
                "{$returnUrl}{$separator}payment=cancelled&invoice={$invoice->id}",
            );
        } catch (RequestException $e) {
            Log::error('PayMongo checkout could not be created', ['invoice_id' => $invoice->id, 'status' => $e->response->status()]);
            abort(502, __('The payment provider is not responding. Please try again in a few minutes.'));
        }

        return Payment::create([
            'firm_id' => $invoice->firm_id,
            'invoice_id' => $invoice->id,
            'provider' => 'paymongo',
            'checkout_id' => $checkout['id'],
            'checkout_url' => $checkout['url'],
            'amount_cents' => $invoice->balanceDue(),
        ]);
    }

    /**
     * Apply a verified webhook event. Idempotent: PayMongo retries
     * deliveries, and a repeat of a settled payment changes nothing.
     */
    public function handleEvent(array $event): void
    {
        if (data_get($event, 'data.attributes.type') !== 'checkout_session.payment.paid') {
            return;
        }

        $session = data_get($event, 'data.attributes.data', []);
        $checkoutId = (string) data_get($session, 'id', '');
        $payment = Payment::withoutGlobalScopes()->where('checkout_id', $checkoutId)->first();

        if ($payment === null) {
            Log::warning('PayMongo payment for an unknown checkout session', ['checkout_id' => $checkoutId]);

            return;
        }

        $this->tenant->runAs($payment->firm_id, fn () => DB::transaction(function () use ($payment, $session) {
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($payment->status !== Payment::PENDING) {
                return;
            }

            $paid = collect(data_get($session, 'attributes.payments', []))
                ->first(fn ($p) => data_get($p, 'attributes.status') === 'paid');
            $amount = (int) data_get($paid, 'attributes.amount', 0);
            $invoice = Invoice::whereKey($payment->invoice_id)->lockForUpdate()->firstOrFail();

            $payment->forceFill([
                'provider_payment_id' => data_get($paid, 'id'),
                'method' => data_get($session, 'attributes.payment_method_used') ?? data_get($paid, 'attributes.source.type'),
                'paid_at' => now(),
            ]);

            // Money that no longer fits (the invoice was settled another way
            // meanwhile, or the amount differs) is held for manual refund.
            if (! $invoice->status->isReceivable() || $amount !== $payment->amount_cents || $amount > $invoice->balanceDue()) {
                $payment->forceFill(['status' => Payment::UNAPPLIED])->save();
                Log::critical('Online payment received that could not be applied to its invoice', [
                    'payment_id' => $payment->id, 'invoice' => $invoice->number,
                    'invoice_status' => $invoice->status->value, 'amount' => $amount, 'expected' => $payment->amount_cents, 'balance' => $invoice->balanceDue(),
                ]);

                return;
            }

            $payment->forceFill(['status' => Payment::PAID])->save();
            $this->payments->record($invoice, [
                'method' => 'online',
                'amount_cents' => $amount,
                'reference' => 'PAYMONGO-'.($payment->provider_payment_id ?? $payment->checkout_id),
                'online_payment_id' => $payment->id,
            ], null);

            $lawyer = User::find(Matter::whereKey($invoice->matter_id)->value('responsible_lawyer_id'));
            $lawyer?->notify(new OnlinePaymentReceived($invoice->load('client:id,name'), $amount));
        }));
    }
}
