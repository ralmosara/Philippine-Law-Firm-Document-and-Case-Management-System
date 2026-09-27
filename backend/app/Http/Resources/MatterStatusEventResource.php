<?php

namespace App\Http\Resources;

use App\Domain\Matters\Models\MatterStatusEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MatterStatusEvent */
class MatterStatusEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'from_status' => $this->from_status?->value,
            'from_label' => $this->from_status?->label(),
            'to_status' => $this->to_status->value,
            'to_label' => $this->to_status->label(),
            'reason' => $this->reason,
            'changed_by' => new UserSummaryResource($this->whenLoaded('changedBy')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
