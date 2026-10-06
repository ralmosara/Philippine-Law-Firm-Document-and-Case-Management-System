<?php

namespace App\Domain\Compliance\Notifications;

use App\Domain\Compliance\Models\ClientIdentification;
use App\Domain\Matters\Models\Client;
use App\Notifications\Concerns\ShowsInApp;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A client's identification is about to expire, or has. */
class IdentificationExpiring extends Notification implements ShouldQueue
{
    use Queueable, ShowsInApp;

    public function __construct(public readonly ClientIdentification $identification, public readonly Client $client, public readonly string $stage) {}

    public function via(object $notifiable): array
    {
        return $this->withInApp($notifiable, ['mail']);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $in = $this->inApp($notifiable);

        return (new MailMessage)->subject($in['title'])->line($in['body'])->line('Ask the client for a current ID and record it on their page.')
            ->action('Open the client', rtrim(config('app.frontend_url'), '/').$in['url']);
    }

    protected function inApp(object $notifiable): array
    {
        $what = "{$this->identification->id_type} of {$this->client->name}";

        return [
            'kind' => 'aml',
            'title' => $this->stage === 'expired' ? "Identification expired: {$this->client->name}" : "Identification expiring: {$this->client->name}",
            'body' => ($this->stage === 'expired' ? "The {$what} expired on " : "The {$what} expires on ").$this->identification->expires_on->format('F j, Y').'.',
            'url' => "/clients/{$this->client->id}",
        ];
    }
}
