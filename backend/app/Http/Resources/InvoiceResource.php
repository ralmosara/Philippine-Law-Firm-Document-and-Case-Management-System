<?php

namespace App\Http\Resources;

use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Payments\OnlinePayments;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Invoice */
class InvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'status' => $this->status->value,
            'is_overdue' => $this->isOverdue(),
            'subtotal_cents' => $this->subtotal_cents,
            'vat_cents' => $this->vat_cents,
            'expenses_cents' => $this->expenses_cents,
            'total_cents' => $this->total_cents,
            'settled_cents' => $this->settled_cents,
            'withholding_cents' => $this->withholding_cents,
            'balance_cents' => $this->balanceDue(),
            'withholding_room_cents' => $this->withholdingRoom(),
            'issued_at' => $this->issued_at?->toDateString(),
            'due_at' => $this->due_at?->toDateString(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'payment_reference' => $this->payment_reference,
            'notes' => $this->notes,
            'client' => $this->whenLoaded('client', fn () => ['id' => $this->client->id, 'name' => $this->client->name]),
            'matter' => $this->whenLoaded('matter', fn () => [
                'id' => $this->matter->id,
                'reference' => $this->matter->reference,
                'title' => $this->matter->title,
            ]),
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn ($line) => [
                'id' => $line->id,
                'kind' => $line->kind,
                'work_date' => $line->work_date?->toDateString(),
                'description' => $line->description,
                'minutes' => $line->minutes,
                'rate_cents' => $line->rate_cents,
                'amount_cents' => $line->amount_cents,
            ])),
            'can_pay_online' => app(OnlinePayments::class)->canPay($this->resource),
            'payments' => $this->whenLoaded('payments', fn () => $this->payments->map(fn ($payment) => [
                'id' => $payment->id,
                'provider' => $payment->provider,
                'status' => $payment->status,
                'method' => $payment->method,
                'amount_cents' => $payment->amount_cents,
                'reference' => $payment->provider_payment_id,
                'paid_at' => $payment->paid_at?->toIso8601String(),
                'created_at' => $payment->created_at?->toIso8601String(),
            ])),
            'invoice_payments' => InvoicePaymentResource::collection($this->whenLoaded('invoicePayments')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
