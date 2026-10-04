<?php

namespace App\Http\Resources;

use App\Domain\Matters\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Client */
class ClientResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'name' => $this->name,
            'tin' => $this->tin,
            'email' => $this->email,
            'phone' => $this->phone,
            'address' => $this->address,
            'notes' => $this->notes,
            'aliases' => $this->aliases,
            'portal_enabled' => $this->portal_enabled,
            'portal_locale' => $this->locale ?? 'en',
            // False until an invited client chooses their password.
            'portal_password_set' => isset($this->resource->getAttributes()['password']),
            'last_portal_login_at' => $this->last_portal_login_at?->toIso8601String(),
            'matters_count' => $this->whenCounted('matters', fn ($count) => (int) $count),
            'active_matters_count' => $this->whenCounted('active_matters', fn ($count) => (int) $count),
            'matters' => MatterResource::collection($this->whenLoaded('matters')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
