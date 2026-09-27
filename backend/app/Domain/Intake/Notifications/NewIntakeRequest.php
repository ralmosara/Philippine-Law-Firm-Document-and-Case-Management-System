<?php

namespace App\Domain\Intake\Notifications;

use App\Domain\Intake\Models\IntakeRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Tells the firm's partners a consultation request arrived, with its conflict-check result. */
class NewIntakeRequest extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly IntakeRequest $request) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $flagged = $this->request->conflict_status === 'flagged';
        $message = (new MailMessage)
            ->subject(($flagged ? '[Possible conflict] ' : '')."New consultation request: {$this->request->name} ({$this->request->case_type})")
            ->line("{$this->request->name} asked for a consultation about a {$this->request->case_type} matter through the firm's intake page.");

        if ($flagged) {
            $message->error()->line('The automatic conflict check found possible matches. Review them before contacting the applicant about the matter.');
        } else {
            $message->line('The automatic conflict check found no matches.');
        }

        return $message->action('Review request', rtrim(config('app.frontend_url'), '/')."/intake/{$this->request->id}");
    }
}
