<?php

namespace App\Domain\Intake\Notifications;

use App\Domain\Intake\Models\IntakeRequest;
use App\Domain\Matters\Models\Firm;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ConsultationScheduled extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly IntakeRequest $request, public readonly Firm $firm) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $when = $this->request->consultation_at?->timezone('Asia/Manila')->format('l, F j, Y \a\t g:i A');

        return (new MailMessage)
            ->subject("Your consultation with {$this->firm->name}")
            ->greeting("Dear {$this->request->name},")
            ->line("Your consultation is scheduled for **{$when}** (Philippine time) with {$this->request->assignedLawyer?->name}.")
            ->line($this->firm->address ? "Venue: {$this->firm->address}." : 'We will send you the venue or video-call details.')
            ->line('Please bring a valid ID and any documents about your concern. If you need to reschedule, reply to this email or call us'.($this->firm->phone ? " at {$this->firm->phone}" : '').'.')
            ->salutation($this->firm->name);
    }
}
