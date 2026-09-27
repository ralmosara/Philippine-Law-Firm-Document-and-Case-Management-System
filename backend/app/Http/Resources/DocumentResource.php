<?php

namespace App\Http\Resources;

use App\Domain\Documents\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Document */
class DocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'status' => $this->status->value,
            'is_editable' => $this->status->isEditable(),
            'current_version' => $this->current_version,
            'shared_with_client' => $this->shared_with_client,
            'matter' => $this->whenLoaded('matter', fn () => [
                'id' => $this->matter->id,
                'reference' => $this->matter->reference,
                'title' => $this->matter->title,
            ]),
            'template' => $this->whenLoaded('template', fn () => $this->template ? ['id' => $this->template->id, 'name' => $this->template->name] : null),
            'creator' => new UserSummaryResource($this->whenLoaded('creator')),
            'latest_version' => new DocumentVersionResource($this->whenLoaded('latestVersion')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
