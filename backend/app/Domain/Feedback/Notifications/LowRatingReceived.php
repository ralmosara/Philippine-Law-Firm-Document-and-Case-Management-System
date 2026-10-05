<?php

namespace App\Domain\Feedback\Notifications;

use App\Domain\Feedback\MatterFeedback;
use App\Notifications\Concerns\ShowsInApp;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A client rated a closed matter poorly: worth a call. */
class LowRatingReceived extends Notification implements ShouldQueue
{
    use Queueable, ShowsInApp;

    public function __construct(public readonly MatterFeedback $feedback) {}

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
            ->line('Consider calling the client, then record what was done on the feedback page.')
            ->action('See the feedback', rtrim(config('app.frontend_url'), '/').$in['url']);
    }

    protected function inApp(object $notifiable): array
    {
        $f = $this->feedback;

        return [
            'kind' => 'feedback',
            'title' => "{$f->client->name} rated {$f->matter->reference} {$f->rating} out of 5",
            'body' => $f->comment ? '"'.mb_strimwidth($f->comment, 0, 200, '…').'"' : 'No comment left.',
            'url' => '/feedback',
        ];
    }
}
