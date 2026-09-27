<?php

namespace App\Http\Resources;

use App\Domain\Matters\Models\Matter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Matter */
class MatterResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'title' => $this->title,
            'case_type' => $this->case_type,
            'case_number' => $this->case_number,
            'court' => $this->court,
            'court_branch' => $this->court_branch,
            'judge' => $this->judge,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'allowed_transitions' => collect($this->status->allowedTransitions())->map(fn ($s) => ['value' => $s->value, 'label' => $s->label()]),
            'description' => $this->description,
            'opened_at' => $this->opened_at?->toDateString(),
            'closed_at' => $this->closed_at?->toDateString(),
            'client_id' => $this->client_id,
            'client' => new ClientResource($this->whenLoaded('client')),
            'responsible_lawyer' => new UserSummaryResource($this->whenLoaded('responsibleLawyer')),
            'parties' => MatterPartyResource::collection($this->whenLoaded('parties')),
            'next_deadline' => new DeadlineResource($this->whenLoaded('nextDeadline')),
            'unbilled_cents' => $this->whenHas('unbilled_cents', fn () => (int) $this->unbilled_cents),
            'unbilled_expenses_cents' => $this->whenHas('unbilled_expenses_cents', fn () => (int) $this->unbilled_expenses_cents),
            'fee_arrangement' => $this->whenHas('fee_arrangement', fn () => $this->fee_arrangement?->value ?? 'hourly'),
            'fee_arrangement_label' => $this->whenHas('fee_arrangement', fn () => $this->fee_arrangement?->label()),
            'fixed_fee_cents' => $this->whenHas('fixed_fee_cents'),
            'acceptance_fee_cents' => $this->whenHas('acceptance_fee_cents'),
            'appearance_fee_cents' => $this->whenHas('appearance_fee_cents'),
            'contingency_basis_points' => $this->whenHas('contingency_basis_points'),
            'client_role' => $this->whenHas('client_role'),
            'nature_of_action' => $this->whenHas('nature_of_action'),
            'retainer_auto_bill' => $this->whenHas('retainer_auto_bill'),
            'retainer_billing_day' => $this->whenHas('retainer_billing_day'),
            'retainer_auto_issue' => $this->whenHas('retainer_auto_issue'),
            'retainer_billed_through' => $this->whenHas('retainer_billed_through', fn () => $this->retainer_billed_through?->toDateString()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
