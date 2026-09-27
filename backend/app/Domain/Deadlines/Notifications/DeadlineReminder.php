<?php

namespace App\Domain\Deadlines\Notifications;

use App\Domain\Deadlines\Enums\ReminderStage;
use App\Domain\Deadlines\Models\MatterDeadline;
use App\Notifications\Channels\SmsChannel;
use App\Notifications\Channels\SmsMessage;
use App\Notifications\Concerns\ShowsInApp;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DeadlineReminder extends Notification
{
    use ShowsInApp;

    public function __construct(
        public readonly MatterDeadline $deadline,
        public readonly ReminderStage $stage,
    ) {}

    public function via(object $notifiable): array
    {
        // SMS only for the final two stages, when it is most likely to matter.
        $urgent = $this->stage->rank() >= ReminderStage::OneDay->rank();

        return $this->withInApp($notifiable, $urgent && $notifiable->routeNotificationFor('sms') ? ['mail', SmsChannel::class] : ['mail']);
    }

    protected function inApp(object $notifiable): array
    {
        $matter = $this->deadline->matter;

        return [
            'kind' => 'deadline',
            'title' => "{$this->deadline->title}: {$this->stage->label()}",
            'body' => "{$matter->reference} {$matter->title}, due ".$this->deadline->due_date->format('M j').($this->deadline->due_time ? ' at '.substr($this->deadline->due_time, 0, 5) : ''),
            'url' => "/matters/{$matter->id}?tab=deadlines",
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $matter = $this->deadline->matter;

        return (new MailMessage)
            ->subject("[{$matter->reference}] {$this->deadline->title} — {$this->stage->label()}")
            ->greeting("Good day, {$notifiable->name}.")
            ->line("**{$this->deadline->title}** for *{$matter->title}* ({$matter->reference}) is {$this->stage->label()}.")
            ->line('Due date: '.$this->deadline->due_date->format('l, F j, Y').($this->deadline->due_time ? ' at '.substr($this->deadline->due_time, 0, 5) : ''))
            ->action('Open matter', rtrim(config('app.frontend_url'), '/')."/matters/{$matter->id}")
            ->line('Please mark the deadline as completed once filed or attended.');
    }

    public function toSms(object $notifiable): SmsMessage
    {
        $matter = $this->deadline->matter;

        return new SmsMessage(sprintf(
            '%s: %s (%s) is %s, %s.',
            config('app.name'),
            $this->deadline->title,
            $matter->reference,
            $this->stage->label(),
            $this->deadline->due_date->format('M j'),
        ));
    }
}
