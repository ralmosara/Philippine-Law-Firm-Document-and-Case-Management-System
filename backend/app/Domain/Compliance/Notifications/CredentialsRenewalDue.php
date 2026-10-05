<?php

namespace App\Domain\Compliance\Notifications;

use App\Notifications\Concerns\ShowsInApp;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A lawyer's PTR or IBP details are not for this year yet. */
class CredentialsRenewalDue extends Notification implements ShouldQueue
{
    use Queueable, ShowsInApp;

    /** @param list<string> $problems */
    public function __construct(public readonly int $year, public readonly array $problems) {}

    public function via(object $notifiable): array
    {
        return $this->withInApp($notifiable, ['mail']);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $in = $this->inApp($notifiable);
        $message = (new MailMessage)->subject($in['title'])
            ->line("Pleadings you sign print your PTR and IBP details, which must be for {$this->year}:");
        foreach ($this->problems as $problem) {
            $message->line("• {$problem}");
        }

        return $message
            ->line('PTR is paid to the city or municipality where you practise, by the end of January. Update your details once renewed.')
            ->action('Update your details', rtrim(config('app.frontend_url'), '/').$in['url']);
    }

    protected function inApp(object $notifiable): array
    {
        return [
            'kind' => 'credentials',
            'title' => "Renew your PTR and IBP details for {$this->year}",
            'body' => implode(' ', $this->problems),
            'url' => '/profile',
        ];
    }
}
