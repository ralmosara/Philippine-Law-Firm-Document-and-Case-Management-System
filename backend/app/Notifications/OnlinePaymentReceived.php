<?php

namespace App\Notifications;

use App\Domain\Billing\Models\Invoice;
use App\Notifications\Concerns\ShowsInApp;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/** In the bell only: a client paid online and the payment was applied. */
class OnlinePaymentReceived extends Notification implements ShouldQueue
{
    use Queueable, ShowsInApp;

    public function __construct(public readonly Invoice $invoice, public readonly int $amountCents) {}

    public function via(object $notifiable): array
    {
        return $this->withInApp($notifiable, []);
    }

    protected function inApp(object $notifiable): array
    {
        return [
            'kind' => 'payment',
            'title' => '₱'.number_format($this->amountCents / 100, 2)." paid online on {$this->invoice->number}",
            'body' => $this->invoice->client?->name,
            'url' => "/billing/invoices/{$this->invoice->id}",
        ];
    }
}
