<?php

namespace App\Domain\Documents\Requests\Notifications;

use App\Domain\Documents\Requests\DocumentRequest;
use App\Domain\Matters\Models\Firm;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Tells a client which documents the firm needs, with a link to upload them. */
class DocumentsRequested extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly DocumentRequest $request) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $request = $this->request->fresh('items');
        $firm = Firm::findOrFail($request->firm_id);
        $message = (new MailMessage)
            ->subject("Documents needed: {$request->title}")
            ->greeting("Dear {$notifiable->name},")
            ->line("{$firm->name} needs the following documents for your matter:");

        foreach ($request->items as $item) {
            $message->line('• '.$item->label.($item->required ? '' : ' (if available)'));
        }
        if ($request->message) {
            $message->line('"'.$request->message.'"');
        }
        if ($request->due_on) {
            $message->line('Please upload them by '.$request->due_on->format('F j, Y').'.');
        }

        return $message
            ->action('Upload in the client portal', rtrim(config('app.frontend_url'), '/')."/portal/requests/{$request->id}")
            ->salutation("Sincerely,\n{$firm->name}");
    }
}
