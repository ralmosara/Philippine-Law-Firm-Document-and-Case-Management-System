<?php

namespace App\Http\Resources;

use App\Domain\Deadlines\Models\DeadlineEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DeadlineEvent */
class DeadlineEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event_type' => $this->event_type,
            'payload' => $this->payload,
            'user' => new UserSummaryResource($this->whenLoaded('user')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
