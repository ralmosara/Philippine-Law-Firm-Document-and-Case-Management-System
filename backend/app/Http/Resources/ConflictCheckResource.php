<?php

namespace App\Http\Resources;

use App\Domain\Compliance\Models\ConflictCheck;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ConflictCheck */
class ConflictCheckResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'search_term' => $this->search_term,
            'status' => $this->status->value,
            'match_count' => $this->match_count,
            'matches' => $this->matches,
            'requester' => new UserSummaryResource($this->whenLoaded('requester')),
            'resolver' => new UserSummaryResource($this->whenLoaded('resolver')),
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'resolution_notes' => $this->resolution_notes,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
