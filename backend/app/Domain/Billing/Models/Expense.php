<?php

namespace App\Domain\Billing\Models;

use App\Casts\DateOnly;
use App\Domain\Billing\Enums\ExpenseCategory;
use App\Domain\Documents\Models\MatterFile;
use App\Domain\Matters\Models\Matter;
use App\Models\Traits\Auditable;
use App\Models\Traits\HasTenantScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A cost advanced for the client (docket fees, TSN, courier, ...), billed at cost. */
class Expense extends Model
{
    use Auditable, HasTenantScope, SoftDeletes;

    protected $fillable = ['firm_id', 'matter_id', 'user_id', 'expense_date', 'category', 'description', 'amount_cents', 'is_billable', 'receipt_file_id'];

    protected $attributes = [
        'is_billable' => true,
    ];

    protected function casts(): array
    {
        return [
            'expense_date' => DateOnly::class,
            'category' => ExpenseCategory::class,
            'amount_cents' => 'integer',
            'is_billable' => 'boolean',
        ];
    }

    public function isInvoiced(): bool
    {
        return $this->invoice_id !== null;
    }

    public function scopeUnbilled(Builder $query): void
    {
        $query->whereNull('invoice_id')->where('is_billable', true);
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(MatterFile::class, 'receipt_file_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
