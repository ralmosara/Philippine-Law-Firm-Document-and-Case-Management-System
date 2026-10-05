<?php

namespace App\Domain\Intake\Notifications;

use App\Domain\Intake\Models\IntakeRequest;
use App\Domain\Matters\Models\Firm;
use App\Support\Localization\PortalLocale;
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
        $at = $this->request->consultation_at?->timezone('Asia/Manila')->locale(app()->getLocale());
        $when = $at ? $at->translatedFormat('l, ').PortalLocale::date($at).', '.$at->format('g:i A') : '';

        return (new MailMessage)
            ->subject(__('Your consultation with :firm', ['firm' => $this->firm->name]))
            ->greeting(__('Dear :name,', ['name' => $this->request->name]))
            ->line(__('Your consultation is scheduled for **:when** (Philippine time) with :lawyer.', ['when' => $when, 'lawyer' => $this->request->assignedLawyer?->name]))
            ->line($this->firm->address ? __('Venue: :address.', ['address' => $this->firm->address]) : __('We will send you the venue or video-call details.'))
            ->line($this->firm->phone
                ? __('Please bring a valid ID and any documents about your concern. If you need to reschedule, reply to this email or call us at :phone.', ['phone' => $this->firm->phone])
                : __('Please bring a valid ID and any documents about your concern. If you need to reschedule, reply to this email.'))
            ->salutation($this->firm->name);
    }
}
