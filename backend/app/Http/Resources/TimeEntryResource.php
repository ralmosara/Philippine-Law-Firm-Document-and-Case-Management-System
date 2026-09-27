<?php

namespace App\Http\Resources;

use App\Domain\Billing\Models\TimeEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TimeEntry */
class TimeEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'work_date' => $this->work_date->toDateString(),
            'minutes' => $this->minutes,
            'rate_cents' => $this->rate_cents,
            'amount_cents' => $this->amount_cents,
            'description' => $this->description,
            'is_billable' => $this->is_billable,
            'invoice_id' => $this->invoice_id,
            'is_invoiced' => $this->isInvoiced(),
            'user' => new UserSummaryResource($this->whenLoaded('user')),
            'matter' => $this->whenLoaded('matter', fn () => [
                'id' => $this->matter->id,
                'reference' => $this->matter->reference,
                'title' => $this->matter->title,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
