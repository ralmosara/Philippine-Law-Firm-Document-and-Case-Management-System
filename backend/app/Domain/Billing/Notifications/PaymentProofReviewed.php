<?php

namespace App\Domain\Billing\Notifications;

use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\PaymentProof;
use App\Domain\Matters\Models\Firm;
use App\Support\Localization\PortalLocale;
use App\Support\Pdf\PdfRenderer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** The client's proof of payment was confirmed (payment recorded) or could not be matched. In their language. */
class PaymentProofReviewed extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly PaymentProof $proof) {}

    public function via(object $notifiable): array
    {
        return filled($notifiable->email) ? ['mail'] : [];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $invoice = Invoice::findOrFail($this->proof->invoice_id);
        $firm = Firm::findOrFail($invoice->firm_id);
        $amount = PdfRenderer::money($this->proof->amount_cents);
        $message = (new MailMessage)->greeting(__('Dear :name,', ['name' => $notifiable->name]));

        if ($this->proof->status === 'confirmed') {
            $message->subject(__('Payment received: :number', ['number' => $invoice->number]))
                ->line(__('Thank you. We have received your payment of :amount on billing statement :number, paid on :date.', ['amount' => $amount, 'number' => $invoice->number, 'date' => PortalLocale::date($this->proof->paid_on)]))
                ->line($invoice->fresh()->balanceDue() > 0
                    ? __('The remaining balance is :balance.', ['balance' => PdfRenderer::money($invoice->fresh()->balanceDue())])
                    : __('This billing statement is now fully paid.'));
        } else {
            $message->subject(__('We could not confirm your payment: :number', ['number' => $invoice->number]))
                ->line(__('We could not match the proof of payment you sent for :amount on billing statement :number with our records.', ['amount' => $amount, 'number' => $invoice->number]))
                ->line(__('Reason: :reason', ['reason' => $this->proof->reject_reason]))
                ->line(__('Please check the details and send it again from the client portal, or reply to this email.'));
        }

        return $message->salutation(__('Sincerely,')."\n{$firm->name}");
    }
}
