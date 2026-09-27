<?php

namespace App\Domain\Documents\Notifications;

use App\Domain\Documents\Models\SignatureRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Tells a client a document is waiting for their signature in the portal. */
class SignatureRequested extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly SignatureRequest $request) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $document = $this->request->document;
        $message = (new MailMessage)
            ->subject("Please sign: {$document->title}")
            ->line("{$this->request->requester?->name} has asked you to review and sign **{$document->title}**.");

        if ($this->request->message) {
            $message->line("\"{$this->request->message}\"");
        }

        if ($this->request->expires_at) {
            $message->line("Please respond by {$this->request->expires_at->format('F j, Y')}.");
        }

        return $message->action('Review and sign', rtrim(config('app.frontend_url'), '/').'/portal');
    }
}
