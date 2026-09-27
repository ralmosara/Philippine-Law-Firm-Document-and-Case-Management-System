<?php

namespace App\Http\Resources;

use App\Domain\Documents\Models\SignatureRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A signature request and, once answered, its evidence (the "certificate").
 *
 * @mixin SignatureRequest
 */
class SignatureRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'document_id' => $this->document_id,
            'status' => $this->isExpired() ? 'expired' : $this->status->value,
            'message' => $this->message,
            'version_number' => $this->whenLoaded('version', fn () => $this->version->version_number),
            'content_sha256' => $this->content_sha256,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'client' => $this->whenLoaded('client', fn () => ['id' => $this->client->id, 'name' => $this->client->name, 'email' => $this->client->email]),
            'requester' => new UserSummaryResource($this->whenLoaded('requester')),
            'responded_at' => $this->responded_at?->toIso8601String(),
            'signer_name' => $this->signer_name,
            'signature_method' => $this->signature_method,
            'signature_image' => $this->signature_image,
            'signer_ip' => $this->signer_ip,
            'signer_user_agent' => $this->signer_user_agent,
            'decline_reason' => $this->decline_reason,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
