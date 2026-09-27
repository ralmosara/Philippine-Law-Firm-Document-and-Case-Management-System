<?php

namespace App\Http\Resources;

use App\Domain\Billing\Models\InvoicePayment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin InvoicePayment */
class InvoicePaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'invoice_id' => $this->invoice_id,
            'received_on' => $this->received_on?->toDateString(),
            'method' => $this->method,
            'amount_cents' => $this->amount_cents,
            'withholding_cents' => $this->withholding_cents,
            'credited_cents' => $this->creditedCents(),
            'reference' => $this->reference,
            'notes' => $this->notes,
            'form_2307_received_at' => $this->form_2307_received_at?->toIso8601String(),
            'form_2307_file_id' => $this->form_2307_file_id,
            'is_trust' => $this->trust_transaction_id !== null,
            'is_online' => $this->online_payment_id !== null,
            'recorded_by' => $this->whenLoaded('recorder', fn () => $this->recorder?->name),
            'voided_at' => $this->voided_at?->toIso8601String(),
            'void_reason' => $this->void_reason,
            'invoice' => $this->whenLoaded('invoice', fn () => [
                'id' => $this->invoice->id,
                'number' => $this->invoice->number,
                'client' => $this->invoice->relationLoaded('client') ? $this->invoice->client?->name : null,
                'matter_id' => $this->invoice->matter_id,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
