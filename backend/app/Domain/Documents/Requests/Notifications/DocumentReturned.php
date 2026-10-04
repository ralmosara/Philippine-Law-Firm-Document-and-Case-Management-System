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
            ->subject(__('Please upload again: :item', ['item' => $item->label]))
            ->greeting(__('Dear :name,', ['name' => $notifiable->name]))
            ->line(__('Thank you for uploading ":item". Unfortunately we cannot use it:', ['item' => $item->label]))
            ->line('"'.$item->review_note.'"')
            ->line(__('Please upload a new copy.'))
            ->action(__('Upload in the client portal'), rtrim(config('app.frontend_url'), '/')."/portal/requests/{$this->request->id}")
            ->salutation(__('Sincerely,')."\n{$firm->name}");
    }
}
