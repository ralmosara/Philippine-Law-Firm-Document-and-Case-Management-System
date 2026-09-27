<?php

namespace App\Domain\Deadlines\Notifications;

use App\Domain\Deadlines\Models\MatterDeadline;
use App\Notifications\Channels\SmsChannel;
use App\Notifications\Channels\SmsMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DeadlineMissed extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly MatterDeadline $deadline) {}

    public function via(object $notifiable): array
    {
        return $notifiable->routeNotificationFor('sms') ? ['mail', SmsChannel::class] : ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $matter = $this->deadline->matter;

        return (new MailMessage)
            ->error()
            ->subject("MISSED: [{$matter->reference}] {$this->deadline->title}")
            ->line("**{$this->deadline->title}** for *{$matter->title}* was due on {$this->deadline->due_date->format('F j, Y')} and was not marked completed.")
            ->line('If it was in fact complied with, record the completion now. Otherwise, consider remedies immediately (e.g. motion for extension or relief).')
            ->action('Open matter', rtrim(config('app.frontend_url'), '/')."/matters/{$matter->id}");
    }

    public function toSms(object $notifiable): SmsMessage
    {
        return new SmsMessage(sprintf(
            'URGENT %s: %s (%s) was due %s and is not marked done.',
            config('app.name'),
            $this->deadline->title,
            $this->deadline->matter->reference,
            $this->deadline->due_date->format('M j'),
        ));
    }
}
