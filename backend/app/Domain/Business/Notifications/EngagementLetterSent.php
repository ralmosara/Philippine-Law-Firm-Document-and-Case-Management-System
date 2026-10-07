<?php

namespace App\Domain\Business\Notifications;

use App\Domain\Business\Models\EngagementLetter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** The engagement letter, with a private link to read and sign it. */
class EngagementLetterSent extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly EngagementLetter $letter, public readonly string $name, public readonly string $firm, public readonly string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Your engagement letter from {$this->firm}")
            ->greeting("Dear {$this->name},")
            ->line("Thank you for choosing {$this->firm}. Our engagement letter sets out the scope of our work, our fees and the other terms on which we will act for you.")
            ->line('Please read it and, if you agree, sign it online. You can draw your signature or type your name.')
            ->action('Read and sign', rtrim(config('app.frontend_url'), '/').'/engage/'.$this->token)
            ->line('The link is private to you and works until '.$this->letter->expires_at?->timezone('Asia/Manila')->format('F j, Y').'. If you have questions, simply reply to this email.')
            ->salutation("Sincerely,\n{$this->firm}");
    }
}
