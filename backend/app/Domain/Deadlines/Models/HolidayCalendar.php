<?php

namespace App\Domain\Deadlines\Models;

use Illuminate\Database\Eloquent\Model;

class HolidayCalendar extends Model
{
    protected $table = 'holiday_calendar';
    
    protected $fillable = [
        'date',
        'description',
        'is_national',
    ];

    protected $casts = [
        'date' => 'date',
        'is_national' => 'boolean',
    ];
}
