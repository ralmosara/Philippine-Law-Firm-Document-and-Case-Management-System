<?php

namespace App\Domain\Compliance\Notifications;

use App\Domain\Compliance\Models\ConflictCheck;
use App\Domain\Compliance\Models\ConflictWaiver;
use App\Notifications\Concerns\ShowsInApp;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** The person asked to consent signed or declined. */
class ConflictWaiverAnswered extends Notification implements ShouldQueue
{
    use Queueable, ShowsInApp;

    public function __construct(public readonly ConflictWaiver $waiver, public readonly ?ConflictCheck $check) {}

    public function via(object $notifiable): array
    {
        return $this->withInApp($notifiable, ['mail']);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $in = $this->inApp($notifiable);

        return (new MailMessage)->subject($in['title'])->line($in['body'])->action('Open the conflict check', rtrim(config('app.frontend_url'), '/').$in['url']);
    }

    protected function inApp(object $notifiable): array
    {
        $signed = $this->waiver->status === 'signed';

        return [
            'kind' => 'conflict',
            'title' => "{$this->waiver->signer_name} ".($signed ? 'consented' : 'declined to consent'),
            'body' => $signed
                ? 'The signed consent is kept with the conflict check for "'.($this->check?->search_term ?? '').'".'
                : ($this->waiver->decline_reason ? "Reason given: {$this->waiver->decline_reason}" : 'No reason was given.'),
            'url' => '/compliance?tab=conflicts&check='.($this->check?->id ?? ''),
        ];
    }
}
