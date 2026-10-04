<?php

namespace App\Domain\Budgets\Notifications;

use App\Domain\Budgets\MatterBudget;
use App\Notifications\Concerns\ShowsInApp;
use App\Support\Pdf\PdfRenderer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A matter has used 80% or all of its budget. */
class BudgetThresholdReached extends Notification implements ShouldQueue
{
    use Queueable, ShowsInApp;

    public function __construct(public readonly MatterBudget $budget, public readonly int $threshold, public readonly array $usage) {}

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
            ->line('Talk to the client before going further over, or revise the budget on the matter.')
            ->action('Open the matter', rtrim(config('app.frontend_url'), '/').$in['url']);
    }

    protected function inApp(object $notifiable): array
    {
        $matter = $this->budget->matter;
        $amount = fn (int $n) => $this->budget->isHours() ? round($n / 60, 1).' h' : PdfRenderer::money($n);

        return [
            'kind' => 'budget',
            'title' => $this->threshold >= 100
                ? "Over budget: {$matter->reference} {$matter->title}"
                : "{$this->usage['percent']}% of budget used: {$matter->reference} {$matter->title}",
            'body' => "{$amount($this->usage['used'])} of {$amount($this->usage['total'])} used.",
            'url' => "/matters/{$matter->id}?tab=billing",
        ];
    }
}
