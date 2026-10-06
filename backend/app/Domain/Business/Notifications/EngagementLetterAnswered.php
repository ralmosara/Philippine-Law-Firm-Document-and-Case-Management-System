<?php

namespace App\Domain\Business\Notifications;

use App\Domain\Business\Models\EngagementLetter;
use App\Domain\Business\Models\Prospect;
use App\Domain\Matters\Models\Matter;
use App\Notifications\Concerns\ShowsInApp;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** The prospect signed (and the matter opened) or declined the engagement letter. */
class EngagementLetterAnswered extends Notification implements ShouldQueue
{
    use Queueable, ShowsInApp;

    public function __construct(public readonly EngagementLetter $letter, public readonly Prospect $prospect, public readonly ?Matter $matter) {}

    public function via(object $notifiable): array
    {
        return $this->withInApp($notifiable, ['mail']);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $in = $this->inApp($notifiable);

        return (new MailMessage)->subject($in['title'])->line($in['body'])->action('Open', rtrim(config('app.frontend_url'), '/').$in['url']);
    }

    protected function inApp(object $notifiable): array
    {
        $name = $this->prospect->name;

        return match (true) {
            $this->letter->status === 'declined' => [
                'kind' => 'prospect',
                'title' => "{$name} declined the engagement letter",
                'body' => $this->letter->decline_reason ? "Reason given: {$this->letter->decline_reason}" : 'No reason was given.',
                'url' => '/prospects',
            ],
            $this->matter !== null => [
                'kind' => 'prospect',
                'title' => "{$name} signed the engagement letter",
                'body' => "Matter {$this->matter->reference} is open, with the agreed fees set and the signed letter filed under Documents.",
                'url' => "/matters/{$this->matter->id}",
            ],
            default => [
                'kind' => 'prospect',
                'title' => "{$name} signed the engagement letter: open the matter",
                'body' => 'The signature is recorded, but the matter could not be opened automatically (for example a conflict check needs resolving). Convert the prospect from the pipeline.',
                'url' => '/prospects',
            ],
        };
    }
}
