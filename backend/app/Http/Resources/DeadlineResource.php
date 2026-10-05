<?php

namespace App\Http\Resources;

use App\Domain\Deadlines\Models\MatterDeadline;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MatterDeadline */
class DeadlineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'matter_id' => $this->matter_id,
            'kind' => $this->kind->value,
            'title' => $this->title,
            'trigger_date' => $this->trigger_date?->toDateString(),
            'due_date' => $this->due_date->toDateString(),
            'due_time' => $this->due_time ? substr($this->due_time, 0, 5) : null,
            'location' => $this->location,
            'notify_client' => (bool) $this->notify_client,
            'status' => $this->status->value,
            'progress' => $this->progress?->value ?? 'todo',
            'priority' => $this->priority?->value ?? 'normal',
            'notes' => $this->notes,
            'repeat' => $this->repeat,
            'repeat_until' => $this->repeat_until?->toDateString(),
            'next_task_id' => $this->next_task_id,
            'days_remaining' => (int) today()->diffInDays($this->due_date, false),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'rule' => $this->whenLoaded('rule', fn () => $this->rule ? [
                'id' => $this->rule->id,
                'name' => $this->rule->name,
                'legal_basis' => $this->rule->legal_basis,
            ] : null),
            'assignee' => new UserSummaryResource($this->whenLoaded('assignee')),
            'matter' => $this->whenLoaded('matter', fn () => [
                'id' => $this->matter->id,
                'reference' => $this->matter->reference,
                'title' => $this->matter->title,
                'case_number' => $this->matter->case_number,
            ]),
            'events' => DeadlineEventResource::collection($this->whenLoaded('events')),
        ];
    }
}
