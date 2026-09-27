<?php

namespace App\Domain\Deadlines\Models;

use App\Casts\DateOnly;
use Illuminate\Database\Eloquent\Model;

/**
 * A nationwide non-working day. Not tenant-scoped: holidays are proclaimed
 * for everyone, and only managing partners may edit them (the manage-firm
 * gate); every change is written to the audit log.
 */
class HolidayCalendar extends Model
{
    protected $table = 'holiday_calendar';

    protected $fillable = ['date', 'name', 'type'];

    protected function casts(): array
    {
        return ['date' => DateOnly::class];
    }
}
