<?php

namespace App\Domain\Correspondence\Notifications;

use App\Domain\Correspondence\Models\MatterEmail;
use App\Domain\Matters\Models\Matter;
use App\Notifications\Concerns\ShowsInApp;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/** Mail from an unknown sender reached a matter's address and waits to be accepted or rejected. */
class EmailNeedsReview extends Notification implements ShouldQueue
{
    use Queueable, ShowsInApp;

    public function __construct(public readonly MatterEmail $email, public readonly Matter $matter) {}

    public function via(object $notifiable): array
    {
        return $this->withInApp($notifiable, []);
    }

    protected function inApp(object $notifiable): array
    {
        return [
            'kind' => 'email',
            'title' => 'Email to file from '.($this->email->from_email ?? 'an unknown sender'),
            'body' => "{$this->matter->reference}: ".($this->email->subject ?? '(no subject)'),
            'url' => "/matters/{$this->matter->id}?tab=files",
        ];
    }
}
