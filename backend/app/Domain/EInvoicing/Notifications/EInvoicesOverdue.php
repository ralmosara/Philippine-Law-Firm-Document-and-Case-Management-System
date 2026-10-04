<?php

namespace App\Domain\EInvoicing\Notifications;

use App\Notifications\Concerns\ShowsInApp;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** E-invoices that should reach the BIR by today and have not. */
class EInvoicesOverdue extends Notification implements ShouldQueue
{
    use Queueable, ShowsInApp;

    /** @param  list<string>  $numbers */
    public function __construct(public readonly array $numbers) {}

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
            ->line('Check the e-invoicing log for the reason (a rejected TIN or address, or the provider being unreachable), fix it and send again.')
            ->action('Open e-invoicing', rtrim(config('app.frontend_url'), '/').$in['url']);
    }

    protected function inApp(object $notifiable): array
    {
        $count = count($this->numbers);

        return [
            'kind' => 'tax',
            'title' => $count === 1 ? "E-invoice for {$this->numbers[0]} not yet sent to the BIR" : "{$count} e-invoices not yet sent to the BIR",
            'body' => 'Due today or earlier: '.implode(', ', array_slice($this->numbers, 0, 10)).($count > 10 ? ', …' : '').'.',
            'url' => '/tax?tab=einvoicing',
        ];
    }
}
