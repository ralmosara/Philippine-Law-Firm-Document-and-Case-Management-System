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
                ->subject(__('Your :firm client portal', ['firm' => $this->firmName]))
                ->line(__(':firm has opened a client portal account for you. There you can follow your matters, read and sign documents, and pay invoices.', ['firm' => $this->firmName]))
                ->action(__('Set your password'), $this->url())
                ->line(__('This link works for 7 days.'));
        }

        return (new MailMessage)
            ->subject(__('Reset your :firm portal password', ['firm' => $this->firmName]))
            ->line(__('Someone asked to reset the password for your :firm client portal account.', ['firm' => $this->firmName]))
            ->action(__('Choose a new password'), $this->url())
            ->line(__('This link works for :minutes minutes. If you did not ask for this, ignore this email; your password stays the same.', ['minutes' => ClientPasswordResets::EXPIRE_MINUTES]));
    }
}
