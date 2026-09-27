<?php

namespace App\Domain\Billing\Models;

use App\Casts\DateOnly;
use App\Domain\Matters\Models\Matter;
use App\Models\Traits\HasTenantScope;
use App\Models\User;
use Database\Factories\TimeEntryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class TimeEntry extends Model
{
    /** @use HasFactory<TimeEntryFactory> */
    use HasFactory, HasTenantScope, SoftDeletes;

    protected $fillable = ['firm_id', 'matter_id', 'user_id', 'work_date', 'minutes', 'rate_cents', 'description', 'is_billable'];

    protected function casts(): array
    {
        return [
            'work_date' => DateOnly::class,
            'minutes' => 'integer',
            'rate_cents' => 'integer',
            'amount_cents' => 'integer',
            'is_billable' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // The amount is always derived; it is never accepted from input.
        static::saving(function (TimeEntry $entry) {
            $entry->amount_cents = ($entry->is_billable ?? true)
                ? static::amountFor($entry->minutes, $entry->rate_cents)
                : 0;
        });
    }

    /**
     * Minutes x hourly rate, rounded half-up to the centavo. Whole hours are
     * multiplied separately to keep intermediate products small.
     */
    public static function amountFor(int $minutes, int $hourlyRateCents): int
    {
        return intdiv($minutes, 60) * $hourlyRateCents + intdiv(($minutes % 60) * $hourlyRateCents + 30, 60);
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

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    protected static function newFactory(): TimeEntryFactory
    {
        return TimeEntryFactory::new();
    }
}
