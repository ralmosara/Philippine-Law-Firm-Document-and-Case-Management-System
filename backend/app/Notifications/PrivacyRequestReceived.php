<?php

namespace App\Notifications;

use App\Domain\Privacy\Models\DataSubjectRequest;
use App\Notifications\Concerns\ShowsInApp;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A data subject request arrived through the portal: it has a response deadline. */
class PrivacyRequestReceived extends Notification implements ShouldQueue
{
    use Queueable, ShowsInApp;

    public function __construct(public readonly DataSubjectRequest $request) {}

    public function via(object $notifiable): array
    {
        return $this->withInApp($notifiable, ['mail']);
    }

    protected function inApp(object $notifiable): array
    {
        return [
            'kind' => 'privacy',
            'title' => "Privacy request from {$this->request->requester_name}",
            'body' => (DataSubjectRequest::TYPES[$this->request->type] ?? $this->request->type).'. Answer by '.$this->request->due_on->format('M j'),
            'url' => '/privacy?tab=requests',
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Data privacy request from {$this->request->requester_name}")
            ->line("{$this->request->requester_name} asked, through the client portal: ".(DataSubjectRequest::TYPES[$this->request->type] ?? $this->request->type).'.')
            ->line('Please answer by '.$this->request->due_on->format('F j, Y').'.')
            ->action('Open data privacy requests', rtrim(config('app.frontend_url'), '/').'/privacy?tab=requests');
    }
}
