<?php

namespace App\Http\Resources;

use App\Domain\Documents\Models\MatterFile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MatterFile */
class MatterFileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'matter_id' => $this->matter_id,
            'name' => $this->original_name,
            'mime_type' => $this->mime_type,
            'size_bytes' => $this->size_bytes,
            'sha256' => $this->sha256,
            'description' => $this->description,
            'shared_with_client' => $this->shared_with_client,
            'scan_status' => $this->scan_status,
            'text_status' => $this->text_status,
            'text_source' => $this->whenHas('text_source'),
            // Search results only: the text around the hits, marked ⟦like this⟧.
            'snippet' => $this->when(array_key_exists('snippet', $this->resource->getAttributes()), fn () => $this->resource->getAttributes()['snippet']),
            'matter' => $this->whenLoaded('matter', fn () => ['id' => $this->matter->id, 'reference' => $this->matter->reference, 'title' => $this->matter->title]),
            'uploader' => new UserSummaryResource($this->whenLoaded('uploader')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
