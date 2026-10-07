<?php

namespace App\Domain\Billing\Notifications;

use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\PaymentProof;
use App\Domain\Matters\Models\Client;
use App\Notifications\Concerns\ShowsInApp;
use App\Support\Pdf\PdfRenderer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A client uploaded proof of a payment: check it against the bank and confirm it. */
class PaymentProofSubmitted extends Notification implements ShouldQueue
{
    use Queueable, ShowsInApp;

    public function __construct(public readonly PaymentProof $proof, public readonly Invoice $invoice, public readonly Client $client) {}

    public function via(object $notifiable): array
    {
        return $this->withInApp($notifiable, ['mail']);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $in = $this->inApp($notifiable);

        return (new MailMessage)->subject($in['title'])->line($in['body'])->line('Check it against the bank or e-wallet records, then confirm it to record the payment, or reject it with a reason the client will see.')
            ->action('Review', rtrim(config('app.frontend_url'), '/').$in['url']);
    }

    protected function inApp(object $notifiable): array
    {
        return [
            'kind' => 'payment',
            'title' => "Proof of payment from {$this->client->name}",
            'body' => PdfRenderer::money($this->proof->amount_cents)." on {$this->invoice->number}, paid ".$this->proof->paid_on->format('M j, Y').($this->proof->reference ? ", ref. {$this->proof->reference}" : '').'.',
            'url' => '/billing?tab=collections',
        ];
    }
}
