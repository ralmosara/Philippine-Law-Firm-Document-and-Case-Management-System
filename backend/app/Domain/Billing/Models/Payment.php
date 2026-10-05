<?php

namespace App\Domain\Billing\Models;

use App\Models\Traits\Auditable;
use App\Models\Traits\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An online payment attempt for an invoice through a payment provider
 * (PayMongo: cards, GCash, Maya). Created as `pending` when the checkout
 * page is opened and settled only by the provider's signed webhook.
 */
class Payment extends Model
{
    use Auditable, HasTenantScope;

    public const PENDING = 'pending';

    public const PAID = 'paid';

    /** Money was received but the invoice had meanwhile been paid or voided; needs a refund or reallocation. */
    public const UNAPPLIED = 'unapplied';

    protected $fillable = [
        'firm_id', 'invoice_id', 'provider', 'checkout_id', 'checkout_url', 'amount_cents',
    ];

    protected $attributes = [
        'status' => self::PENDING,
    ];

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'paid_at' => 'datetime',
            'refunded_at' => 'datetime',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
