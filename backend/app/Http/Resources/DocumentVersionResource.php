<?php

namespace App\Http\Resources;

use App\Domain\Documents\Models\DocumentVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DocumentVersion */
class DocumentVersionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'version_number' => $this->version_number,
            'content' => $this->content,
            'change_summary' => $this->change_summary,
            'creator' => new UserSummaryResource($this->whenLoaded('creator')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
