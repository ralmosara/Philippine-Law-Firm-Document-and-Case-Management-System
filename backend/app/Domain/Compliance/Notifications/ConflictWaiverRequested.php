<?php

namespace App\Domain\Compliance\Notifications;

use App\Domain\Compliance\Models\ConflictWaiver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A request to consent, in writing, to the firm acting despite a possible conflict. */
class ConflictWaiverRequested extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly ConflictWaiver $waiver, public readonly string $firm, public readonly string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Your consent is requested: {$this->firm}")
            ->greeting("Dear {$this->waiver->signer_name},")
            ->line("{$this->firm} asks for your written consent before acting in a matter where there may be a conflict of interest with you. The letter explains the situation.")
            ->line('Please read it carefully. You are free to refuse, and to consult another lawyer first.')
            ->action('Read the letter', rtrim(config('app.frontend_url'), '/').'/consent/'.$this->token)
            ->line('The link is private to you and works until '.$this->waiver->expires_at?->timezone('Asia/Manila')->format('F j, Y').'.')
            ->salutation("Sincerely,\n{$this->firm}");
    }
}
