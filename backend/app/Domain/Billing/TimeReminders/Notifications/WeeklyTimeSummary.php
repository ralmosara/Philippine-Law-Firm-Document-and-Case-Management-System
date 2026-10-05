<?php

namespace App\Domain\Billing\TimeReminders\Notifications;

use App\Notifications\Concerns\ShowsInApp;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Last week's hours against target, for each person, to the managing partner. */
class WeeklyTimeSummary extends Notification implements ShouldQueue
{
    use Queueable, ShowsInApp;

    /** @param list<array{name: string, minutes: int, target: int}> $rows */
    public function __construct(public readonly CarbonImmutable $from, public readonly CarbonImmutable $to, public readonly array $rows) {}

    public function via(object $notifiable): array
    {
        return $this->withInApp($notifiable, ['mail']);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $in = $this->inApp($notifiable);
        $message = (new MailMessage)->subject($in['title'])->line($in['body']);
        foreach ($this->rows as $r) {
            $message->line("{$r['name']}: ".self::hours($r['minutes']).' of '.self::hours($r['target']).($r['target'] ? ' ('.round(100 * $r['minutes'] / $r['target']).'%)' : ''));
        }

        return $message->action('Open time entries', rtrim(config('app.frontend_url'), '/').$in['url']);
    }

    protected function inApp(object $notifiable): array
    {
        $below = count(array_filter($this->rows, fn ($r) => $r['minutes'] < $r['target']));

        return [
            'kind' => 'time',
            'title' => 'Time logged, week of '.$this->from->format('F j'),
            'body' => $below === 0
                ? 'Everyone met their target ('.$this->from->format('M j').' to '.$this->to->format('M j').').'
                : "{$below} of ".count($this->rows).' below target ('.$this->from->format('M j').' to '.$this->to->format('M j').').',
            'url' => '/billing?tab=time',
        ];
    }

    private static function hours(int $minutes): string
    {
        return rtrim(rtrim(number_format($minutes / 60, 1), '0'), '.').' h';
    }
}
