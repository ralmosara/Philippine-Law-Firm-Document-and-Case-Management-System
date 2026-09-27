<?php

namespace App\Notifications;

use App\Domain\Deadlines\Models\MatterDeadline;
use App\Domain\Matters\Models\Matter;
use App\Models\User;
use App\Notifications\Concerns\ShowsInApp;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/** In the bell only: someone gave you a matter, a deadline or a task. */
class WorkAssigned extends Notification implements ShouldQueue
{
    use Queueable, ShowsInApp;

    public function __construct(public readonly Matter|MatterDeadline $work, public readonly User $by) {}

    public function via(object $notifiable): array
    {
        return $this->withInApp($notifiable, []);
    }

    protected function inApp(object $notifiable): array
    {
        if ($this->work instanceof Matter) {
            return [
                'kind' => 'assigned',
                'title' => "{$this->by->name} made you responsible for {$this->work->reference}",
                'body' => $this->work->title,
                'url' => "/matters/{$this->work->id}",
            ];
        }

        $matter = $this->work->matter;

        return [
            'kind' => 'assigned',
            'title' => "{$this->by->name} assigned you: {$this->work->title}",
            'body' => "{$matter?->reference} · due ".$this->work->due_date->format('M j'),
            'url' => $this->work->kind->value === 'task' ? '/tasks' : "/matters/{$this->work->matter_id}?tab=deadlines",
        ];
    }
}
