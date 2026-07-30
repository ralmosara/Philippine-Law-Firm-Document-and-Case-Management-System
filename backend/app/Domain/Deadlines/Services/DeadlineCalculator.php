<?php

namespace App\Domain\Deadlines\Services;

use Carbon\Carbon;
use App\Domain\Deadlines\Models\HolidayCalendar;
use Illuminate\Support\Collection;

class DeadlineCalculator
{
    /**
     * @var Collection<string>
     */
    protected $holidays;

    public function __construct()
    {
        // Cache holidays in memory for the duration of the request/job
        $this->holidays = HolidayCalendar::pluck('date')->map(fn($date) => $date->format('Y-m-d'));
    }

    /**
     * Calculate the due date based on PH Rules of Court conventions.
     * Excludes the first day, includes the last.
     * If the last day falls on a weekend or holiday, moves to the next working day.
     * 
     * @param Carbon $triggerDate
     * @param int $periodDays
     * @param string $periodType 'calendar' | 'working_days'
     * @return Carbon
     */
    public function calculate(Carbon $triggerDate, int $periodDays, string $periodType = 'calendar'): Carbon
    {
        $dueDate = $triggerDate->copy();

        if ($periodType === 'working_days') {
            // Count exactly N working days, skipping weekends and holidays
            $daysAdded = 0;
            while ($daysAdded < $periodDays) {
                $dueDate->addDay();
                if ($this->isWorkingDay($dueDate)) {
                    $daysAdded++;
                }
            }
            return $dueDate;
        }

        // Calendar days calculation
        // Add N calendar days directly (first day is excluded because addDays(N) natively does this)
        $dueDate->addDays($periodDays);

        // If the resulting date falls on a weekend or holiday, move forward to the next working day
        while (!$this->isWorkingDay($dueDate)) {
            $dueDate->addDay();
        }

        return $dueDate;
    }

    /**
     * Check if a given date is a working day (not a weekend, not a holiday).
     */
    protected function isWorkingDay(Carbon $date): bool
    {
        if ($date->isWeekend()) {
            return false;
        }

        if ($this->holidays->contains($date->format('Y-m-d'))) {
            return false;
        }

        return true;
    }
}
