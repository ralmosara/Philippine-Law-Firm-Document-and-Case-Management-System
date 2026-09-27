<?php

namespace App\Domain\Documents\Requests\Notifications;

use App\Domain\Documents\Requests\DocumentRequest;
use App\Domain\Matters\Models\Firm;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A reminder of documents still missing, before or after the due date. */
class DocumentsReminder extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param  list<string>  $missing */
    public function __construct(public readonly DocumentRequest $request, public readonly array $missing, public readonly string $stage) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $firm = Firm::findOrFail($this->request->firm_id);
        $due = $this->request->due_on?->format('F j, Y');
        $message = (new MailMessage)
            ->subject(($this->stage === 'overdue' ? 'Still needed' : 'Reminder').": documents for {$this->request->title}")
            ->greeting("Dear {$notifiable->name},")
            ->line($this->stage === 'overdue'
                ? "We have not yet received these documents, which we needed by {$due}:"
                : "A reminder that we need these documents by {$due}:");

        foreach ($this->missing as $label) {
            $message->line("• {$label}");
        }

        return $message
            ->action('Upload in the client portal', rtrim(config('app.frontend_url'), '/')."/portal/requests/{$this->request->id}")
            ->line('If something is hard to get, reply to this e-mail and we will help.')
            ->salutation("Sincerely,\n{$firm->name}");
    }
}
