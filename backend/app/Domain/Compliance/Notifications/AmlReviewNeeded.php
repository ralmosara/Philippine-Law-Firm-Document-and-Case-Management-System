<?php

namespace App\Domain\Compliance\Notifications;

use App\Domain\Compliance\Models\AmlReview;
use App\Domain\Matters\Models\Client;
use App\Notifications\Concerns\ShowsInApp;
use App\Support\Pdf\PdfRenderer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A client's trust deposits reached the covered-transaction amount: decide whether to report. */
class AmlReviewNeeded extends Notification implements ShouldQueue
{
    use Queueable, ShowsInApp;

    public function __construct(public readonly AmlReview $review, public readonly ?Client $client) {}

    public function via(object $notifiable): array
    {
        return $this->withInApp($notifiable, ['mail']);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $in = $this->inApp($notifiable);

        return (new MailMessage)->subject($in['title'])->line($in['body'])
            ->line('Decide whether a covered or suspicious transaction report is due, file it if so, and record the decision. Reports have deadlines.')
            ->action('Review', rtrim(config('app.frontend_url'), '/').$in['url']);
    }

    protected function inApp(object $notifiable): array
    {
        return [
            'kind' => 'aml',
            'title' => 'Trust deposits to review: '.($this->client?->name ?? 'a client'),
            'body' => PdfRenderer::money($this->review->amount_cents).' deposited in trust on '.$this->review->day->format('M j, Y').', at or above the firm\'s covered-transaction amount.',
            'url' => '/compliance?tab=aml',
        ];
    }
}
