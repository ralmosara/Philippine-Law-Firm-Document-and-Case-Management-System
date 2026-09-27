<?php

namespace App\Http\Resources;

use App\Domain\Deadlines\Models\DeadlineRule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DeadlineRule */
class DeadlineRuleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'trigger_event' => $this->trigger_event,
            'period_days' => $this->period_days,
            'period_type' => $this->period_type,
            'legal_basis' => $this->legal_basis,
            'notes' => $this->notes,
            'is_active' => $this->is_active,
            'is_system' => $this->isSystemRule(),
        ];
    }
}
