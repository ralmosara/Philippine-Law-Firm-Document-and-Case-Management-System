<?php

namespace App\Http\Resources;

use App\Domain\Trust\Models\TrustAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TrustAccount */
class TrustAccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'account_number' => $this->account_number,
            'balance_cents' => $this->balance_cents,
            'status' => $this->status,
            'client' => $this->whenLoaded('client', fn () => ['id' => $this->client->id, 'name' => $this->client->name]),
            'matter' => $this->whenLoaded('matter', fn () => $this->matter ? [
                'id' => $this->matter->id,
                'reference' => $this->matter->reference,
                'title' => $this->matter->title,
            ] : null),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
