<?php

namespace App\Domain\Billing;

use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\PaymentProof;
use App\Domain\Billing\Notifications\PaymentProofReviewed;
use App\Domain\Billing\Notifications\PaymentProofSubmitted;
use App\Domain\Billing\Services\InvoicePayments;
use App\Domain\Documents\Actions\StoreMatterFile;
use App\Domain\Matters\Models\Client;
use App\Domain\Matters\Models\Matter;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Clients who pay by bank transfer or e-wallet upload the slip or
 * screenshot against the bill. It is virus-scanned and filed with the
 * matter; finance confirms it (which records the payment, with the
 * reference and the image kept) or rejects it with a reason the client sees.
 */
class PaymentProofs
{
    public function __construct(private readonly StoreMatterFile $files, private readonly InvoicePayments $payments) {}

    /** @param array{amount_cents: int, paid_on: string, method: string, reference?: ?string, note?: ?string} $data */
    public function submit(Invoice $invoice, Client $client, UploadedFile $upload, array $data): PaymentProof
    {
        if ((int) $invoice->client_id !== (int) $client->id) {
            abort(404);
        }
        if (! $invoice->status->isReceivable()) {
            throw ValidationException::withMessages(['file' => __('This bill is not awaiting payment.')]);
        }
        if ($data['amount_cents'] > $invoice->balanceDue()) {
            throw ValidationException::withMessages(['amount_cents' => __('This is more than the balance of :balance.', ['balance' => '₱'.number_format($invoice->balanceDue() / 100, 2)])]);
        }

        $matter = Matter::findOrFail($invoice->matter_id);
        $stored = $this->files->execute($matter, $upload, $client, "Proof of payment for {$invoice->number}");

        $proof = PaymentProof::create([
            'firm_id' => $invoice->firm_id,
            'invoice_id' => $invoice->id,
            'client_id' => $client->id,
            'matter_file_id' => $stored->id,
            'amount_cents' => $data['amount_cents'],
            'paid_on' => $data['paid_on'],
            'method' => $data['method'],
            'reference' => $data['reference'] ?? null,
            'note' => $data['note'] ?? null,
        ]);

        User::query()->where('is_active', true)->whereIn('role', [Role::ManagingPartner->value, Role::Partner->value])->get()
            ->each->notify(new PaymentProofSubmitted($proof, $invoice, $client));

        return $proof;
    }

    /** Record the payment as the client reported it (or as corrected from the bank's records). */
    public function confirm(PaymentProof $proof, User $by, ?int $amount = null, int $withholding = 0, ?string $receivedOn = null): PaymentProof
    {
        $proof = DB::transaction(function () use ($proof, $by, $amount, $withholding, $receivedOn) {
            $proof = $this->lockPending($proof);
            $invoice = Invoice::findOrFail($proof->invoice_id);
            $payment = $this->payments->record($invoice, [
                'received_on' => $receivedOn ?? $proof->paid_on->toDateString(),
                'method' => $proof->method,
                'amount_cents' => $amount ?? $proof->amount_cents,
                'withholding_cents' => $withholding,
                'reference' => $proof->reference,
                'notes' => 'From the proof of payment the client uploaded in the portal.',
            ], $by);

            $proof->forceFill(['status' => 'confirmed', 'reviewed_by' => $by->id, 'reviewed_at' => now(), 'invoice_payment_id' => $payment->id])->save();

            return $proof;
        });
        Client::find($proof->client_id)?->notify(new PaymentProofReviewed($proof));

        return $proof;
    }

    public function reject(PaymentProof $proof, string $reason, User $by): PaymentProof
    {
        $proof = DB::transaction(function () use ($proof, $reason, $by) {
            $proof = $this->lockPending($proof);
            $proof->forceFill(['status' => 'rejected', 'reviewed_by' => $by->id, 'reviewed_at' => now(), 'reject_reason' => $reason])->save();

            return $proof;
        });
        Client::find($proof->client_id)?->notify(new PaymentProofReviewed($proof));

        return $proof;
    }

    private function lockPending(PaymentProof $proof): PaymentProof
    {
        $locked = PaymentProof::whereKey($proof->id)->lockForUpdate()->firstOrFail();
        if ($locked->status !== 'pending') {
            throw ValidationException::withMessages(['proof' => "This proof of payment was already {$locked->status}."]);
        }

        return $locked;
    }
}
