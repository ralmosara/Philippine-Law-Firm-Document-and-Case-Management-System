<?php

namespace App\Domain\EInvoicing;

use App\Domain\Billing\Models\Invoice;
use App\Domain\EInvoicing\Notifications\EInvoicesOverdue;
use App\Domain\EInvoicing\Transmitters\HttpTransmitter;
use App\Domain\EInvoicing\Transmitters\RecordOnlyTransmitter;
use App\Domain\EInvoicing\Transmitters\Transmitter;
use App\Domain\Matters\Models\Firm;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Throwable;

/**
 * Electronic invoicing: when a firm has it on, every invoice it issues
 * becomes an e-invoice sent through the configured transmitter, and every
 * invoice it voids after that, a cancellation. Each one is kept with its
 * fingerprint, the provider's reference and answer, and the date by which
 * it must reach the BIR; those still unsent near that date are flagged.
 */
class EInvoicing
{
    /** Voided before it was ever sent: nothing to cancel with the BIR. */
    public const WITHDRAWN = 'withdrawn';

    public function enabled(Firm $firm): bool
    {
        return (bool) $firm->einvoicing_enabled;
    }

    /** On issuing an invoice. */
    public function queueFor(Invoice $invoice): ?EInvoice
    {
        $firm = Firm::findOrFail($invoice->firm_id);
        if (! $this->enabled($firm)) {
            return null;
        }

        $payload = EInvoiceDocument::forInvoice($invoice);
        $eInvoice = EInvoice::firstOrCreate(
            ['invoice_id' => $invoice->id, 'kind' => EInvoice::INVOICE],
            $this->attributes($invoice, $payload),
        );
        $this->dispatch($eInvoice);

        return $eInvoice;
    }

    /** On voiding an invoice: cancel what was sent, or withdraw what was not. */
    public function cancel(Invoice $invoice): ?EInvoice
    {
        $original = EInvoice::query()->where('invoice_id', $invoice->id)->where('kind', EInvoice::INVOICE)->first();
        if ($original === null || $original->status === self::WITHDRAWN) {
            return null;
        }

        if (in_array($original->status, [EInvoice::PENDING, EInvoice::FAILED, EInvoice::REJECTED], true)) {
            $original->forceFill(['status' => self::WITHDRAWN, 'error' => null])->save();

            return null;
        }

        $cancellation = EInvoice::firstOrCreate(
            ['invoice_id' => $invoice->id, 'kind' => EInvoice::CANCELLATION],
            $this->attributes($invoice, EInvoiceDocument::forCancellation($invoice, $original)),
        );
        $this->dispatch($cancellation);

        return $cancellation;
    }

    /** Send one e-invoice; a RuntimeException means "try again later". */
    public function transmit(EInvoice $eInvoice): EInvoice
    {
        if (in_array($eInvoice->status, [...EInvoice::SETTLED, EInvoice::SUBMITTED, self::WITHDRAWN], true)) {
            return $eInvoice;
        }

        $transmitter = $this->transmitter();
        $eInvoice->forceFill(['attempts' => $eInvoice->attempts + 1, 'driver' => $transmitter->name()]);

        try {
            $result = $transmitter->transmit($eInvoice);
        } catch (RuntimeException $e) {
            $eInvoice->forceFill(['status' => EInvoice::FAILED, 'error' => mb_substr($e->getMessage(), 0, 2000)])->save();

            throw $e;
        }

        $eInvoice->forceFill([
            'status' => $result->status,
            'provider_reference' => $result->reference ?? $eInvoice->provider_reference,
            'error' => $result->error,
            'submitted_at' => $eInvoice->submitted_at ?? ($result->status !== EInvoice::REJECTED ? now() : null),
            'accepted_at' => $result->status === EInvoice::ACCEPTED ? now() : $eInvoice->accepted_at,
        ])->save();

        return $eInvoice;
    }

    /** Send again after a rejection was fixed, or a provider outage. */
    public function retry(EInvoice $eInvoice): EInvoice
    {
        if (! $eInvoice->canRetry()) {
            return $eInvoice;
        }

        // A rejected invoice may have been corrected since: rebuild it from the invoice.
        if ($eInvoice->kind === EInvoice::INVOICE && $eInvoice->status === EInvoice::REJECTED) {
            $payload = EInvoiceDocument::forInvoice($eInvoice->invoice);
            $eInvoice->forceFill(['payload' => $payload, 'payload_sha256' => $this->fingerprint($payload)]);
        }
        $eInvoice->forceFill(['status' => EInvoice::PENDING, 'error' => null])->save();
        $this->dispatch($eInvoice);

        return $eInvoice->refresh();
    }

    /** Daily: e-invoices due today or late and not yet with the BIR (or kept on file). */
    public function flagOverdue(Firm $firm): int
    {
        $late = EInvoice::query()
            ->where('firm_id', $firm->id)
            ->whereNotIn('status', [...EInvoice::SETTLED, EInvoice::SUBMITTED, self::WITHDRAWN])
            ->whereDate('due_on', '<=', today())
            ->whereNull('overdue_notified_at')
            ->with('invoice:id,number')
            ->get();

        if ($late->isNotEmpty()) {
            $recipients = User::query()->where('firm_id', $firm->id)->where('is_active', true)->get()->filter(fn (User $u) => $u->role->canManageFinances());
            Notification::send($recipients, new EInvoicesOverdue($late->pluck('invoice.number')->filter()->values()->all()));
            EInvoice::query()->whereKey($late->modelKeys())->update(['overdue_notified_at' => now()]);
        }

        return $late->count();
    }

    public function transmitter(): Transmitter
    {
        return match (config('services.einvoicing.driver', 'record')) {
            'http' => new HttpTransmitter(
                (string) config('services.einvoicing.endpoint'),
                (string) config('services.einvoicing.token'),
                (string) config('services.einvoicing.secret'),
            ),
            default => new RecordOnlyTransmitter,
        };
    }

    /** Which transmitter is configured, without failing on a bad configuration. */
    public function driverStatus(): array
    {
        try {
            $name = $this->transmitter()->name();

            return ['driver' => $name, 'ready' => true, 'problem' => null];
        } catch (Throwable $e) {
            return ['driver' => (string) config('services.einvoicing.driver'), 'ready' => false, 'problem' => $e->getMessage()];
        }
    }

    private function attributes(Invoice $invoice, array $payload): array
    {
        return [
            'firm_id' => $invoice->firm_id,
            'driver' => (string) config('services.einvoicing.driver', 'record'),
            'payload' => $payload,
            'payload_sha256' => $this->fingerprint($payload),
            'due_on' => now()->timezone('Asia/Manila')->addDays((int) config('services.einvoicing.deadline_days', 3))->toDateString(),
        ];
    }

    private function fingerprint(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function dispatch(EInvoice $eInvoice): void
    {
        if ($eInvoice->status === EInvoice::PENDING) {
            TransmitEInvoice::dispatch($eInvoice->firm_id, $eInvoice->id)->afterCommit();
        }
    }
}
