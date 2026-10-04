<?php

namespace App\Domain\Prescription\Notifications;

use App\Domain\Prescription\MatterPrescription;
use App\Notifications\Concerns\ShowsInApp;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A cause of action on a matter is about to prescribe, or has. */
class PrescriptionApproaching extends Notification implements ShouldQueue
{
    use Queueable, ShowsInApp;

    public function __construct(public readonly MatterPrescription $prescription, public readonly int $daysLeft) {}

    public function via(object $notifiable): array
    {
        return $this->withInApp($notifiable, ['mail']);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $in = $this->inApp($notifiable);
        $p = $this->prescription;

        return (new MailMessage)
            ->subject($in['title'])
            ->line($in['body'])
            ->line("Period: {$this->period()} from {$p->runs_from->format('F j, Y')}".($p->basis ? " ({$p->basis})" : '').'.')
            ->line('Check the computation against the facts of the case. If the action has been filed, or prescription was interrupted, record it on the matter.')
            ->action('Open the matter', rtrim(config('app.frontend_url'), '/').$in['url']);
    }

    protected function inApp(object $notifiable): array
    {
        $p = $this->prescription;
        $matter = $p->matter;
        $when = match (true) {
            $this->daysLeft < 0 => 'prescribed on '.$p->last_day->format('M j, Y'),
            $this->daysLeft === 0 => 'prescribes today',
            $this->daysLeft === 1 => 'prescribes tomorrow',
            default => "prescribes in {$this->daysLeft} days",
        };
        $fileBy = $p->file_by->ne($p->last_day) && $this->daysLeft >= 0
            ? " (falls on a non-working day; the next working day is {$p->file_by->format('M j, Y')})"
            : '';

        return [
            'kind' => 'prescription',
            'title' => "{$p->label} {$when}: {$matter->reference}",
            'body' => "{$matter->title}. Last day: {$p->last_day->format('l, F j, Y')}{$fileBy}.",
            'url' => "/matters/{$matter->id}?tab=deadlines",
        ];
    }

    private function period(): string
    {
        $years = $this->prescription->years;
        $months = $this->prescription->months;

        return implode(' and ', array_filter([
            $years ? $years.' '.($years === 1 ? 'year' : 'years') : null,
            $months ? $months.' '.($months === 1 ? 'month' : 'months') : null,
        ]));
    }
}
