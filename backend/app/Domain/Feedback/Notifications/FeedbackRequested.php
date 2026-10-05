<?php

namespace App\Domain\Feedback\Notifications;

use App\Domain\Feedback\MatterFeedback;
use App\Domain\Matters\Models\Firm;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Asks the client, in their language, how the firm did on a matter just closed. */
class FeedbackRequested extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly MatterFeedback $feedback) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $firm = Firm::findOrFail($this->feedback->firm_id);
        $matter = $this->feedback->matter;

        return (new MailMessage)
            ->subject(__('How did we do? :firm', ['firm' => $firm->name]))
            ->greeting(__('Dear :name,', ['name' => $notifiable->name]))
            ->line(__('Your matter ":title" is now closed. Thank you for trusting :firm with it.', ['title' => $matter->title, 'firm' => $firm->name]))
            ->line(__('Would you tell us how we did? It takes a minute: a rating and, if you like, a comment. Only the firm sees your answer.'))
            ->action(__('Give feedback'), rtrim(config('app.frontend_url'), '/')."/portal/matters/{$matter->id}")
            ->salutation(__('Sincerely,')."\n{$firm->name}");
    }
}
