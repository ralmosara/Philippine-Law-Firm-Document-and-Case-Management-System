<?php

namespace App\Domain\Intake\Notifications;

use App\Domain\Intake\Models\IntakeRequest;
use App\Domain\Matters\Models\Firm;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A courteous decline that never states the reason: saying "conflict of
 * interest" would itself reveal another client's confidential information.
 */
class IntakeDeclined extends Notification implements ShouldQueue
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
            ->subject(__('Your consultation request: :firm', ['firm' => $this->firm->name]))
            ->greeting(__('Dear :name,', ['name' => $this->request->name]))
            ->line(__('Thank you for considering :firm. After review, we are unable to take on your matter.', ['firm' => $this->firm->name]))
            ->line(__('This is not a judgment on the merits of your concern. Legal remedies can be subject to deadlines, so we encourage you to consult another lawyer promptly. The Integrated Bar of the Philippines chapter in your area or the Public Attorney’s Office may be able to help.'))
            ->salutation($this->firm->name);
    }
}
