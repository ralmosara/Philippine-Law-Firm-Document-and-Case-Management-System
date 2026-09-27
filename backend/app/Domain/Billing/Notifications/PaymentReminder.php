<?php

namespace App\Domain\Billing\Notifications;

use App\Domain\Billing\Collections\InvoiceReminder;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Payments\OnlinePayments;
use App\Domain\Matters\Models\Firm;
use App\Http\Controllers\Api\V1\InvoiceController;
use App\Support\Pdf\PdfRenderer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A polite reminder of an unpaid balance, with the billing statement attached. */
class PaymentReminder extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Invoice $invoice, public readonly string $stage) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $invoice = $this->invoice->fresh();
        $firm = Firm::findOrFail($invoice->firm_id);
        $balance = '₱'.number_format($invoice->balanceDue() / 100, 2);
        $due = $invoice->due_at?->format('F j, Y');

        $message = (new MailMessage)->greeting("Dear {$notifiable->name},");
        match ($this->stage) {
            InvoiceReminder::DUE_SOON => $message
                ->subject("Billing statement {$invoice->number} is due on {$due}")
                ->line("This is a friendly reminder that {$balance} on billing statement {$invoice->number} is due on {$due}."),
            InvoiceReminder::OVERDUE_30 => $message
                ->subject("Second reminder: billing statement {$invoice->number} is past due")
                ->line("Our records show {$balance} on billing statement {$invoice->number}, due on {$due}, is still unpaid.")
                ->line('If there is a problem with this statement, please reply so we can sort it out.'),
            default => $message
                ->subject("Reminder: billing statement {$invoice->number} is past due")
                ->line("Our records show {$balance} on billing statement {$invoice->number}, due on {$due}, is still unpaid."),
        };

        if ($notifiable->portal_enabled) {
            $message->action(app(OnlinePayments::class)->canPay($invoice) ? 'View and pay online' : 'View in the client portal', rtrim(config('app.frontend_url'), '/').'/portal');
        }

        return $message
            ->line('If you have already paid, thank you, and please disregard this message; you may reply with your proof of payment so we can update our records.')
            ->salutation("Sincerely,\n{$firm->name}")
            ->attachData(app(PdfRenderer::class)->render('pdf.invoice', InvoiceController::pdfData($invoice)), "billing-statement-{$invoice->number}.pdf", ['mime' => 'application/pdf']);
    }
}
