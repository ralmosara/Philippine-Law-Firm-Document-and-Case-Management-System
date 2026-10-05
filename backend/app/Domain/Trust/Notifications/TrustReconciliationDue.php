<?php

namespace App\Domain\Trust\Notifications;

use App\Notifications\Concerns\ShowsInApp;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Last month's trust funds have not been reconciled with the bank and signed off. */
class TrustReconciliationDue extends Notification implements ShouldQueue
{
    use Queueable, ShowsInApp;

    public function __construct(public readonly CarbonImmutable $period) {}

    public function via(object $notifiable): array
    {
        return $this->withInApp($notifiable, ['mail']);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $in = $this->inApp($notifiable);

        return (new MailMessage)
            ->subject($in['title'])
            ->line($in['body'])
            ->line('Enter the bank statement balance, deposits not yet on the statement and uncleared cheques; the ledger and client balances are filled in for you.')
            ->action('Reconcile', rtrim(config('app.frontend_url'), '/').$in['url']);
    }

    protected function inApp(object $notifiable): array
    {
        return [
            'kind' => 'trust',
            'title' => 'Trust reconciliation for '.$this->period->format('F Y').' is due',
            'body' => 'Client trust funds for '.$this->period->format('F Y').' have not been reconciled with the bank and signed off.',
            'url' => '/trust?tab=reconciliation',
        ];
    }
}
