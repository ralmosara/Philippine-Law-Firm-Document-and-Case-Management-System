<?php

namespace App\Domain\Documents\Notifications;

use App\Domain\Documents\Models\NotarialReport;
use App\Notifications\Concerns\ShowsInApp;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Last month's notarial report is not yet marked as sent to the clerk of court. */
class NotarialReportDue extends Notification implements ShouldQueue
{
    use Queueable, ShowsInApp;

    public function __construct(public readonly NotarialReport $report, public readonly CarbonImmutable $dueOn) {}

    public function via(object $notifiable): array
    {
        return $this->withInApp($notifiable, ['mail']);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $in = $this->inApp($notifiable);

        return (new MailMessage)->subject($in['title'])->line($in['body'])
            ->line('Download the report, sign the certification, send it with the certified copy to the clerk of court, then mark it submitted.')
            ->action('Open the notarial register', rtrim(config('app.frontend_url'), '/').$in['url']);
    }

    protected function inApp(object $notifiable): array
    {
        $month = $this->report->period->format('F Y');
        $acts = $this->report->entries === 0 ? 'no notarial acts' : ($this->report->entries === 1 ? '1 notarial act' : "{$this->report->entries} notarial acts");

        return [
            'kind' => 'notarial',
            'title' => "Notarial report for {$month} due by ".$this->dueOn->format('F j'),
            'body' => "Your register shows {$acts} in {$month}. Submit the certified copy to the clerk of court by ".$this->dueOn->format('F j, Y').'.',
            'url' => '/documents?tab=notarial',
        ];
    }
}
