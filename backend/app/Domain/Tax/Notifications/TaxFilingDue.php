<?php

namespace App\Domain\Tax\Notifications;

use App\Domain\Tax\BirCalendar;
use App\Domain\Tax\Models\TaxFiling;
use App\Notifications\Concerns\ShowsInApp;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A BIR return of the firm's is coming due, or is overdue. */
class TaxFilingDue extends Notification implements ShouldQueue
{
    use Queueable, ShowsInApp;

    public function __construct(public readonly TaxFiling $filing, public readonly string $stage) {}

    public function via(object $notifiable): array
    {
        return $this->withInApp($notifiable, ['mail']);
    }

    private function when(): string
    {
        $date = $this->filing->due_on->format('F j, Y');

        return match ($this->stage) {
            'overdue' => "was due {$date}",
            'tomorrow' => "is due {$date}",
            default => "is due in a week ({$date})",
        };
    }

    protected function inApp(object $notifiable): array
    {
        return [
            'kind' => $this->stage === 'overdue' ? 'deadline_missed' : 'tax',
            'title' => "BIR {$this->filing->form} for {$this->filing->period} {$this->when()}",
            'body' => BirCalendar::FORMS[$this->filing->form] ?? null,
            'url' => '/tax?tab=calendar',
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(($this->stage === 'overdue' ? 'Overdue: ' : '')."BIR {$this->filing->form} for {$this->filing->period} {$this->when()}")
            ->line((BirCalendar::FORMS[$this->filing->form] ?? $this->filing->form).", period {$this->filing->period}, {$this->when()}.")
            ->line('Mark it filed in the tax calendar once submitted, with the confirmation or payment reference.')
            ->action('Open the tax calendar', rtrim(config('app.frontend_url'), '/').'/tax?tab=calendar');
    }
}
