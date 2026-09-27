<?php

namespace App\Domain\Billing\Models;

use App\Casts\DateOnly;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Matters\Models\Client;
use App\Domain\Matters\Models\Matter;
use App\Models\Traits\Auditable;
use App\Models\Traits\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    use Auditable, HasTenantScope;

    protected $fillable = [
        'firm_id',
        'client_id',
        'matter_id',
        'number',
        'subtotal_cents',
        'vat_cents',
        'expenses_cents',
        'total_cents',
        'due_at',
        'notes',
        'created_by',
    ];

    protected $attributes = [
        'status' => 'draft',
        'settled_cents' => 0,
        'withholding_cents' => 0,
    ];

    protected function casts(): array
    {
        return [
            'status' => InvoiceStatus::class,
            'subtotal_cents' => 'integer',
            'vat_cents' => 'integer',
            'expenses_cents' => 'integer',
            'total_cents' => 'integer',
            'issued_at' => DateOnly::class,
            'due_at' => DateOnly::class,
            'paid_at' => 'datetime',
            'settled_cents' => 'integer',
            'withholding_cents' => 'integer',
        ];
    }

    /** Still owed: the total less cash received and tax withheld. */
    public function balanceDue(): int
    {
        return max(0, $this->total_cents - (int) ($this->attributes['settled_cents'] ?? 0));
    }

    /**
     * Largest creditable withholding still allowed: the tax base is the
     * professional fees (before VAT); expenses billed at cost are excluded.
     */
    public function withholdingRoom(): int
    {
        return max(0, $this->subtotal_cents - (int) ($this->attributes['withholding_cents'] ?? 0));
    }

    public function invoicePayments(): HasMany
    {
        return $this->hasMany(InvoicePayment::class)->orderBy('received_on')->orderBy('id');
    }

    public static function nextNumber(int $firmId, int $year): string
    {
        $prefix = "INV-{$year}-";

        $last = static::withoutGlobalScopes()
            ->where('firm_id', $firmId)
            ->where('number', 'like', $prefix.'%')
            ->orderByDesc('number')
            ->value('number');

        $sequence = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $sequence, 5, '0', STR_PAD_LEFT);
    }

    public function isOverdue(): bool
    {
        return $this->status->isReceivable() && $this->due_at?->isPast();
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class);
    }

    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->latest('id');
    }
}
