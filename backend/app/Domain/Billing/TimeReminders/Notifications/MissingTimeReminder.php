<?php

namespace App\Domain\Billing\TimeReminders\Notifications;

use App\Notifications\Concerns\ShowsInApp;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Less time logged on the previous working day than the daily target. */
class MissingTimeReminder extends Notification implements ShouldQueue
{
    use Queueable, ShowsInApp;

    public function __construct(public readonly CarbonImmutable $day, public readonly int $minutes, public readonly int $target) {}

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
            ->line('Time logged while it is fresh is more accurate, and time not logged is never billed.')
            ->action('Log time', rtrim(config('app.frontend_url'), '/').$in['url']);
    }

    protected function inApp(object $notifiable): array
    {
        $hours = fn (int $m) => rtrim(rtrim(number_format($m / 60, 1), '0'), '.').' h';

        return [
            'kind' => 'time',
            'title' => 'Time for '.$this->day->format('l, F j').': '.$hours($this->minutes).' logged',
            'body' => $this->minutes === 0
                ? "No time logged for {$this->day->format('F j')}. Your daily target is {$hours($this->target)}."
                : "{$hours($this->minutes)} of your {$hours($this->target)} daily target logged for {$this->day->format('F j')}.",
            'url' => '/billing?tab=time',
        ];
    }
}
