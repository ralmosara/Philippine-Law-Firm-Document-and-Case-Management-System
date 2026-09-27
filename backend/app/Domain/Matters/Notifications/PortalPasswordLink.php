<?php

namespace App\Domain\Matters\Notifications;

use App\Domain\Matters\Services\ClientPasswordResets;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A client-portal password link: either an invitation or a reset. */
class PortalPasswordLink extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $token,
        public readonly int $clientId,
        public readonly string $firmName,
        public readonly bool $invite,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function url(): string
    {
        return rtrim(config('app.frontend_url'), '/')."/portal/reset-password?client={$this->clientId}&token={$this->token}".($this->invite ? '&invite=1' : '');
    }

    public function toMail(object $notifiable): MailMessage
    {
        if ($this->invite) {
            return (new MailMessage)
                ->subject("Your {$this->firmName} client portal")
                ->line("{$this->firmName} has opened a client portal account for you. There you can follow your matters, read and sign documents, and pay invoices.")
                ->action('Set your password', $this->url())
                ->line('This link works for 7 days.');
        }

        return (new MailMessage)
            ->subject("Reset your {$this->firmName} portal password")
            ->line("Someone asked to reset the password for your {$this->firmName} client portal account.")
            ->action('Choose a new password', $this->url())
            ->line('This link works for '.ClientPasswordResets::EXPIRE_MINUTES.' minutes. If you did not ask for this, ignore this email; your password stays the same.');
    }
}
