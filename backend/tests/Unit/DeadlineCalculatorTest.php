<?php

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Domain\Deadlines\Services\DeadlineCalculator;
use App\Domain\Deadlines\Models\HolidayCalendar;
use Carbon\Carbon;

class DeadlineCalculatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_basic_calendar_days_calculation_no_holidays_or_weekends_involved()
    {
        // Start on Monday, add 3 days -> Should land on Thursday
        $calculator = new DeadlineCalculator();
        $start = Carbon::parse('2026-07-06'); // Monday
        $due = $calculator->calculate($start, 3, 'calendar');

        $this->assertEquals('2026-07-09', $due->format('Y-m-d')); // Thursday
    }

    public function test_calendar_days_lands_on_weekend_shifts_to_monday()
    {
        // Start on Wednesday, add 3 days -> Lands on Saturday -> shifts to Monday
        $calculator = new DeadlineCalculator();
        $start = Carbon::parse('2026-07-08'); // Wednesday
        $due = $calculator->calculate($start, 3, 'calendar');

        $this->assertEquals('2026-07-13', $due->format('Y-m-d')); // Monday
    }

    public function test_calendar_days_lands_on_holiday_shifts_to_next_working_day()
    {
        // Start on Monday, add 3 days -> Lands on Thursday. 
        // Let's make Thursday a holiday, so it should shift to Friday.
        HolidayCalendar::create(['date' => '2026-07-09', 'description' => 'Test Holiday']);
        
        $calculator = new DeadlineCalculator();
        $start = Carbon::parse('2026-07-06'); // Monday
        $due = $calculator->calculate($start, 3, 'calendar');

        $this->assertEquals('2026-07-10', $due->format('Y-m-d')); // Friday
    }

    public function test_calendar_days_lands_on_holiday_friday_shifts_to_monday()
    {
        // Start on Tuesday, add 3 days -> Lands on Friday.
        // Make Friday a holiday -> Should shift to Monday since Sat/Sun are weekends.
        HolidayCalendar::create(['date' => '2026-07-10', 'description' => 'Friday Holiday']);
        
        $calculator = new DeadlineCalculator();
        $start = Carbon::parse('2026-07-07'); // Tuesday
        $due = $calculator->calculate($start, 3, 'calendar');

        $this->assertEquals('2026-07-13', $due->format('Y-m-d')); // Monday
    }

    public function test_working_days_calculation_skips_weekends()
    {
        // Start on Thursday, add 3 working days.
        // Thu -> Fri (1), Sat (skip), Sun (skip), Mon (2), Tue (3)
        $calculator = new DeadlineCalculator();
        $start = Carbon::parse('2026-07-09'); // Thursday
        $due = $calculator->calculate($start, 3, 'working_days');

        $this->assertEquals('2026-07-14', $due->format('Y-m-d')); // Tuesday
    }

    public function test_working_days_calculation_skips_holidays()
    {
        // Start on Wednesday, add 3 working days.
        // Wed -> Thu (1), Fri is Holiday (skip), Sat (skip), Sun (skip), Mon (2), Tue (3)
        HolidayCalendar::create(['date' => '2026-07-10', 'description' => 'Friday Holiday']);
        
        $calculator = new DeadlineCalculator();
        $start = Carbon::parse('2026-07-08'); // Wednesday
        $due = $calculator->calculate($start, 3, 'working_days');

        $this->assertEquals('2026-07-14', $due->format('Y-m-d')); // Tuesday
    }
}

