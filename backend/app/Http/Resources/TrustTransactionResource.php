<?php

namespace App\Http\Resources;

use App\Domain\Trust\Models\TrustTransaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TrustTransaction */
class TrustTransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'amount_cents' => $this->amount_cents,
            'signed_amount_cents' => $this->type->signedAmount($this->amount_cents),
            'balance_after_cents' => $this->balance_after_cents,
            'reference' => $this->reference,
            'description' => $this->description,
            'creator' => new UserSummaryResource($this->whenLoaded('creator')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
