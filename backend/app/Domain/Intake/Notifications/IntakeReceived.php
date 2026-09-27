<?php

namespace App\Domain\Intake\Notifications;

use App\Domain\Intake\Models\IntakeRequest;
use App\Domain\Matters\Models\Firm;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Acknowledgement to the applicant. Makes clear no engagement exists yet. */
class IntakeReceived extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly IntakeRequest $request, public readonly Firm $firm) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("We received your consultation request: {$this->firm->name}")
            ->greeting("Dear {$this->request->name},")
            ->line("Thank you for contacting {$this->firm->name}. We received your request about a {$this->request->case_type} matter and will get back to you to confirm a consultation schedule.")
            ->line('Please note that sending this request does not create a lawyer-client relationship, and nothing in this exchange is legal advice. Until we confirm that we can take your case, please do not send confidential documents.')
            ->line('Your information is processed only to respond to your request, in accordance with the Data Privacy Act of 2012.')
            ->salutation($this->firm->name.($this->firm->phone ? " · {$this->firm->phone}" : ''));
    }
}
