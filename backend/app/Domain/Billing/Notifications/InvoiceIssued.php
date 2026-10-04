<?php

namespace App\Domain\Billing\Notifications;

use App\Domain\Billing\Models\Invoice;
use App\Domain\Matters\Models\Firm;
use App\Http\Controllers\Api\V1\InvoiceController;
use App\Support\Localization\PortalLocale;
use App\Support\Pdf\PdfRenderer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Sends a newly issued billing statement to the client. */
class InvoiceIssued extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Invoice $invoice) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $invoice = $this->invoice->fresh();
        $firm = Firm::findOrFail($invoice->firm_id);
        $message = (new MailMessage)
            ->subject(__('Billing statement :number from :firm', ['number' => $invoice->number, 'firm' => $firm->name]))
            ->greeting(__('Dear :name,', ['name' => $notifiable->name]))
            ->line(__('Please find attached billing statement :number for :amount, due on :due.', ['number' => $invoice->number, 'amount' => '₱'.number_format($invoice->total_cents / 100, 2), 'due' => PortalLocale::date($invoice->due_at)]));

        if ($notifiable->portal_enabled) {
            $message->action(__('View in the client portal'), rtrim(config('app.frontend_url'), '/').'/portal');
        }

        return $message
            ->salutation(__('Sincerely,')."\n{$firm->name}")
            ->attachData(app(PdfRenderer::class)->render('pdf.invoice', InvoiceController::pdfData($invoice)), "billing-statement-{$invoice->number}.pdf", ['mime' => 'application/pdf']);
    }
}
