<?php

namespace App\Domain\Billing\Models;

use App\Casts\DateOnly;
use App\Domain\Documents\Models\MatterFile;
use App\Models\Traits\Auditable;
use App\Models\Traits\HasTenantScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money received against an invoice, plus any creditable withholding tax
 * the client deducted (to be evidenced by BIR Form 2307). Never deleted;
 * a mistaken entry is voided with a reason.
 */
class InvoicePayment extends Model
{
    use Auditable, HasTenantScope;

    public const METHODS = ['cash', 'check', 'bank_transfer', 'e_wallet', 'card', 'online', 'trust', 'other'];

    protected $fillable = [
        'firm_id', 'invoice_id', 'received_on', 'method', 'amount_cents', 'withholding_cents',
        'reference', 'notes', 'form_2307_received_at', 'form_2307_file_id', 'trust_transaction_id',
        'online_payment_id', 'recorded_by',
    ];

    protected $attributes = [
        'withholding_cents' => 0,
    ];

    protected function casts(): array
    {
        return [
            'received_on' => DateOnly::class,
            'amount_cents' => 'integer',
            'withholding_cents' => 'integer',
            'form_2307_received_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    /** What this payment settles on the invoice. */
    public function creditedCents(): int
    {
        return $this->amount_cents + $this->withholding_cents;
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }

    public function scopeActive(Builder $query): void
    {
        $query->whereNull('voided_at');
    }

    /** Tax withheld for which the client's Form 2307 has not arrived yet. */
    public function scopeAwaiting2307(Builder $query): void
    {
        $query->whereNull('voided_at')->where('withholding_cents', '>', 0)->whereNull('form_2307_received_at');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function form2307File(): BelongsTo
    {
        return $this->belongsTo(MatterFile::class, 'form_2307_file_id');
    }
}
