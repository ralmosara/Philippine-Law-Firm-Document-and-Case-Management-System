<?php

namespace App\Domain\Corporate\Notifications;

use App\Domain\Corporate\Models\CorporateObligation;
use App\Notifications\Concerns\ShowsInApp;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A client company's SEC or BIR obligation is coming due, or is overdue. */
class CorporateObligationDue extends Notification implements ShouldQueue
{
    use Queueable, ShowsInApp;

    public function __construct(public readonly CorporateObligation $obligation, public readonly string $stage) {}

    public function via(object $notifiable): array
    {
        return $this->withInApp($notifiable, ['mail']);
    }

    private function when(): string
    {
        $date = $this->obligation->due_on->format('F j, Y');

        return match ($this->stage) {
            'overdue' => "was due {$date}",
            'day' => "is due {$date}",
            'week' => "is due in a week ({$date})",
            default => "is due in a month ({$date})",
        };
    }

    protected function inApp(object $notifiable): array
    {
        return [
            'kind' => $this->stage === 'overdue' ? 'deadline_missed' : 'corporate',
            'title' => "{$this->obligation->client?->name}: {$this->obligation->title} {$this->when()}",
            'body' => 'Corporate secretarial',
            'url' => "/corporate?client={$this->obligation->client_id}",
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(($this->stage === 'overdue' ? 'Overdue: ' : '')."{$this->obligation->client?->name}: {$this->obligation->title}")
            ->line("{$this->obligation->title} for {$this->obligation->client?->name} {$this->when()}.")
            ->line('Mark it done in the corporate secretarial page once filed or held, with the SEC or BIR reference.')
            ->action('Open corporate secretarial', rtrim(config('app.frontend_url'), '/')."/corporate?client={$this->obligation->client_id}");
    }
}
