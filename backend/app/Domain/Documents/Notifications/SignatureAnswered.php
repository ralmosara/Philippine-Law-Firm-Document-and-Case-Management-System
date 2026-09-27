<?php

namespace App\Domain\Documents\Notifications;

use App\Domain\Documents\Enums\SignatureStatus;
use App\Domain\Documents\Models\SignatureRequest;
use App\Notifications\Concerns\ShowsInApp;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Tells the lawyer who asked that the client has signed or declined. */
class SignatureAnswered extends Notification implements ShouldQueue
{
    use Queueable, ShowsInApp;

    public function __construct(public readonly SignatureRequest $request) {}

    public function via(object $notifiable): array
    {
        return $this->withInApp($notifiable, ['mail']);
    }

    protected function inApp(object $notifiable): array
    {
        $signed = $this->request->status === SignatureStatus::Signed;

        return [
            'kind' => $signed ? 'signature' : 'signature_declined',
            'title' => ($signed ? 'Signed: ' : 'Declined: ').$this->request->document->title,
            'body' => $signed ? "By {$this->request->signer_name}" : ('Reason: '.($this->request->decline_reason ?: 'none given')),
            'url' => "/documents/{$this->request->document_id}",
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $document = $this->request->document;
        $client = $this->request->client;
        $url = rtrim(config('app.frontend_url'), '/')."/documents/{$document->id}";

        if ($this->request->status === SignatureStatus::Signed) {
            return (new MailMessage)
                ->subject("Signed: {$document->title}")
                ->line("{$this->request->signer_name} ({$client->name}) signed **{$document->title}** on {$this->request->responded_at->format('F j, Y g:i A')}.")
                ->action('View document', $url);
        }

        return (new MailMessage)
            ->subject("Declined: {$document->title}")
            ->line("{$client->name} declined to sign **{$document->title}**.")
            ->line('Reason: '.($this->request->decline_reason ?: 'none given'))
            ->action('View document', $url);
    }
}
