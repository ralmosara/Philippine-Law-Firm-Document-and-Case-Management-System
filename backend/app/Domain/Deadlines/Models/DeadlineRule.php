<?php

namespace App\Domain\Deadlines\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A reglementary period, e.g. "Notice of Appeal: 15 days from receipt of
 * judgment". Rules with a null firm_id are system-wide Rules of Court
 * periods; firms may add their own.
 */
class DeadlineRule extends Model
{
    protected $fillable = ['firm_id', 'name', 'trigger_event', 'period_days', 'period_type', 'legal_basis', 'notes', 'is_active'];

    protected function casts(): array
    {
        return [
            'period_days' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** System rules plus the given firm's own rules. */
    public function scopeAvailableTo(Builder $query, int $firmId): void
    {
        $query->where(fn (Builder $q) => $q->whereNull('firm_id')->orWhere('firm_id', $firmId));
    }

    public function isSystemRule(): bool
    {
        return $this->firm_id === null;
    }
}
