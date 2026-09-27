<?php

namespace App\Http\Resources;

use App\Domain\Billing\Models\Expense;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Expense */
class ExpenseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'matter_id' => $this->matter_id,
            'expense_date' => $this->expense_date?->toDateString(),
            'category' => $this->category->value,
            'category_label' => $this->category->label(),
            'description' => $this->description,
            'amount_cents' => $this->amount_cents,
            'is_billable' => $this->is_billable,
            'is_invoiced' => $this->isInvoiced(),
            'invoice_id' => $this->invoice_id,
            'receipt' => $this->whenLoaded('receipt', fn () => $this->receipt ? ['id' => $this->receipt->id, 'name' => $this->receipt->original_name] : null),
            'user' => new UserSummaryResource($this->whenLoaded('user')),
            'matter' => $this->whenLoaded('matter', fn () => ['id' => $this->matter->id, 'reference' => $this->matter->reference, 'title' => $this->matter->title]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
