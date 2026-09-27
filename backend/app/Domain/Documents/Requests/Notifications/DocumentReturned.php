<?php

namespace App\Domain\Documents\Requests\Notifications;

use App\Domain\Documents\Requests\DocumentRequest;
use App\Domain\Documents\Requests\DocumentRequestItem;
use App\Domain\Matters\Models\Firm;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** An uploaded document could not be used: what was wrong, and where to send another. */
class DocumentReturned extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly DocumentRequestItem $item, public readonly DocumentRequest $request) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $item = $this->item->fresh();
        $firm = Firm::findOrFail($this->request->firm_id);

        return (new MailMessage)
            ->subject("Please upload again: {$item->label}")
            ->greeting("Dear {$notifiable->name},")
            ->line("Thank you for uploading \"{$item->label}\". Unfortunately we cannot use it:")
            ->line('"'.$item->review_note.'"')
            ->line('Please upload a new copy.')
            ->action('Upload in the client portal', rtrim(config('app.frontend_url'), '/')."/portal/requests/{$this->request->id}")
            ->salutation("Sincerely,\n{$firm->name}");
    }
}
