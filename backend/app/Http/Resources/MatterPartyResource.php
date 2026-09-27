<?php

namespace App\Http\Resources;

use App\Domain\Matters\Models\MatterParty;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MatterParty */
class MatterPartyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'role' => $this->role->value,
            'role_label' => $this->role->label(),
            'is_adverse' => $this->role->isAdverse(),
            'name' => $this->name,
            'counsel_name' => $this->counsel_name,
            'contact' => $this->contact,
            'notes' => $this->notes,
        ];
    }
}
