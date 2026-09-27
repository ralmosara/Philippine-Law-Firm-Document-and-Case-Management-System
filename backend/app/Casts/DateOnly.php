<?php

namespace App\Casts;

use DateTimeInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A calendar date with no time component, stored as Y-m-d.
 *
 * Eloquent's built-in `date` cast stores "Y-m-d 00:00:00", which on drivers
 * without a native DATE type (SQLite) breaks range comparisons such as
 * `due_date <= '2026-07-09'`. Legal deadlines are dates, not instants.
 *
 * @implements CastsAttributes<Carbon, DateTimeInterface|string>
 */
class DateOnly implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Carbon
    {
        return $value === null ? null : Carbon::parse(substr((string) $value, 0, 10))->startOfDay();
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return ($value instanceof DateTimeInterface ? $value : Carbon::parse($value))->format('Y-m-d');
    }
}
