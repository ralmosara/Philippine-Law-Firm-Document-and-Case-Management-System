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
            'minimum_balance_cents' => $this->whenHas('minimum_balance_cents'),
            'below_minimum' => $this->whenHas('minimum_balance_cents', fn () => $this->minimum_balance_cents !== null && $this->balance_cents < $this->minimum_balance_cents),
            'replenishment_requested_at' => $this->whenHas('replenishment_requested_at', fn () => $this->replenishment_requested_at?->toIso8601String()),
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
