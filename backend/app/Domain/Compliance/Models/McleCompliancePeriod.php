<?php

namespace App\Domain\Compliance\Models;

use App\Casts\DateOnly;
use Illuminate\Database\Eloquent\Model;

/**
 * An MCLE compliance period set by the Supreme Court (Bar Matter 850).
 * Global reference data, not firm-owned.
 */
class McleCompliancePeriod extends Model
{
    protected $fillable = ['name', 'start_date', 'end_date', 'required_units'];

    protected function casts(): array
    {
        return [
            'start_date' => DateOnly::class,
            'end_date' => DateOnly::class,
            'required_units' => 'integer',
        ];
    }

    /** The period covering the given date (today by default). */
    public static function current(?\DateTimeInterface $on = null): ?self
    {
        $date = ($on ?? now())->format('Y-m-d');

        return static::whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->orderByDesc('start_date')
            ->first();
    }
}
