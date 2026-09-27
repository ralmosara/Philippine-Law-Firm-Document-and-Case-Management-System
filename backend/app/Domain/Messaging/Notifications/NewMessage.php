<?php

namespace App\Domain\Messaging\Notifications;

use App\Domain\Messaging\Models\MessageThread;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "You have a new message." The message itself is never put in the email,
 * which would take privileged communication outside the secure portal.
 */
class NewMessage extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly MessageThread $thread, public readonly bool $forClient) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $base = rtrim(config('app.frontend_url'), '/');
        $reference = $this->thread->matter?->reference;

        if ($this->forClient) {
            return (new MailMessage)
                ->subject('New message from your lawyer')
                ->line("You have a new message about your matter {$reference}: \"{$this->thread->subject}\".")
                ->line('For your privacy, the message can only be read in the client portal.')
                ->action('Read the message', "{$base}/portal/messages/{$this->thread->id}");
        }

        return (new MailMessage)
            ->subject("[{$reference}] New message from {$this->thread->client?->name}")
            ->line("{$this->thread->client?->name} wrote in \"{$this->thread->subject}\".")
            ->action('Open conversation', "{$base}/messages/{$this->thread->id}");
    }
}
