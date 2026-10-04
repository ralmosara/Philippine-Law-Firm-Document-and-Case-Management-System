<?php

namespace App\Domain\Documents\Notifications;

use App\Domain\Documents\Models\SignatureRequest;
use App\Support\Localization\PortalLocale;
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
            ->subject(__('Please sign: :title', ['title' => $document->title]))
            ->line(__(':name has asked you to review and sign **:title**.', ['name' => $this->request->requester?->name, 'title' => $document->title]));

        if ($this->request->message) {
            $message->line("\"{$this->request->message}\"");
        }

        if ($this->request->expires_at) {
            $message->line(__('Please respond by :date.', ['date' => PortalLocale::date($this->request->expires_at)]));
        }

        return $message->action(__('Review and sign'), rtrim(config('app.frontend_url'), '/').'/portal');
    }
}
