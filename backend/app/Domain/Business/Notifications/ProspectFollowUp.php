<?php

namespace App\Domain\Business\Notifications;

use App\Domain\Business\Models\Prospect;
use App\Notifications\Concerns\ShowsInApp;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/** A follow-up the owner set for a prospect is due. */
class ProspectFollowUp extends Notification implements ShouldQueue
{
    use Queueable, ShowsInApp;

    public function __construct(public readonly Prospect $prospect) {}

    public function via(object $notifiable): array
    {
        return $this->withInApp($notifiable, []);
    }

    protected function inApp(object $notifiable): array
    {
        return [
            'kind' => 'prospect',
            'title' => "Follow up with {$this->prospect->name}".($this->prospect->next_step ? ": {$this->prospect->next_step}" : ''),
            'body' => Prospect::STAGES[$this->prospect->stage] ?? $this->prospect->stage,
            'url' => "/prospects?open={$this->prospect->id}",
        ];
    }
}
