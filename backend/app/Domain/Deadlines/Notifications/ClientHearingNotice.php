<?php

namespace App\Domain\Deadlines\Notifications;

use App\Domain\Deadlines\Models\MatterDeadline;
use App\Domain\Matters\Models\Firm;
use App\Notifications\Channels\SmsChannel;
use App\Notifications\Channels\SmsMessage;
use App\Support\Localization\PortalLocale;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A client's hearing: set, moved, cancelled, or coming up. By email (and
 * SMS when the client has a mobile number), in the client's language.
 * Only the case title, date, time and venue: nothing from the firm's notes.
 */
class ClientHearingNotice extends Notification implements ShouldQueue
{
    use Queueable;

    public const SCHEDULED = 'scheduled';

    public const MOVED = 'moved';

    public const CANCELLED = 'cancelled';

    public const NEXT_WEEK = 'next_week';

    public const TOMORROW = 'tomorrow';

    public function __construct(
        public readonly MatterDeadline $hearing,
        public readonly Firm $firm,
        public readonly string $kind,
        public readonly ?string $from = null,
    ) {}

    public function via(object $notifiable): array
    {
        return array_values(array_filter([
            filled($notifiable->email) ? 'mail' : null,
            $notifiable->routeNotificationFor('sms') ? SmsChannel::class : null,
        ]));
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject($this->headline().': '.$this->hearing->matter->title)
            ->greeting(__('Dear :name,', ['name' => $notifiable->name]))
            ->line($this->sentence());

        if ($this->kind !== self::CANCELLED) {
            $message->line(__('When: :when', ['when' => $this->when()]));
            if ($venue = $this->venue()) {
                $message->line(__('Where: :venue', ['venue' => $venue]));
            }
            $message->line(__('Please bring a valid ID and come early. If you cannot attend, or are unsure whether you need to, contact your lawyer as soon as possible.'));
        }

        return $message->salutation(__('Sincerely,')."\n{$this->firm->name}".($this->firm->phone ? " · {$this->firm->phone}" : ''));
    }

    /** Short enough for one or two text messages. */
    public function toSms(object $notifiable): SmsMessage
    {
        $parts = [$this->firm->name.': '.$this->headline().'.', $this->hearing->matter->title.'.'];
        if ($this->kind !== self::CANCELLED) {
            $parts[] = $this->when().($this->venue() ? ', '.$this->venue() : '').'.';
        }
        $parts[] = $this->firm->phone ? __('Questions: :phone', ['phone' => $this->firm->phone]) : null;

        return new SmsMessage(implode(' ', array_filter($parts)));
    }

    private function headline(): string
    {
        return match ($this->kind) {
            self::SCHEDULED => __('Hearing set'),
            self::MOVED => __('Hearing moved'),
            self::CANCELLED => __('Hearing cancelled'),
            self::NEXT_WEEK => __('Hearing next week'),
            default => __('Hearing tomorrow'),
        };
    }

    private function sentence(): string
    {
        $title = $this->hearing->matter->title;

        return match ($this->kind) {
            self::SCHEDULED => __('A hearing has been set in your case ":title".', ['title' => $title]),
            self::MOVED => __('The hearing in your case ":title" set for :from has been moved.', ['title' => $title, 'from' => PortalLocale::date(CarbonImmutable::parse((string) $this->from))]),
            self::CANCELLED => __('The hearing in your case ":title" set for :date has been cancelled. Your lawyer will tell you the new date once it is set.', ['title' => $title, 'date' => PortalLocale::date($this->hearing->due_date)]),
            self::NEXT_WEEK => __('A reminder: your hearing in ":title" is next week.', ['title' => $title]),
            default => __('A reminder: your hearing in ":title" is tomorrow.', ['title' => $title]),
        };
    }

    private function when(): string
    {
        $date = CarbonImmutable::parse($this->hearing->due_date->toDateString())->locale(app()->getLocale());
        $day = $date->translatedFormat('l').', '.PortalLocale::date($date);

        return $this->hearing->due_time ? $day.', '.CarbonImmutable::parse($this->hearing->due_time)->format('g:i A') : $day;
    }

    private function venue(): ?string
    {
        $matter = $this->hearing->matter;

        return $this->hearing->location ?: (collect([$matter->court_branch, $matter->court])->filter()->implode(', ') ?: null);
    }
}
