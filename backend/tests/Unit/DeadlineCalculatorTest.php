<?php

namespace Tests\Unit;

use App\Domain\Deadlines\Models\HolidayCalendar;
use App\Domain\Deadlines\Services\DeadlineCalculator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Rule 22, Sec. 1, Rules of Court: exclude the first day, include the last;
 * a last day falling on a weekend or holiday moves to the next working day.
 */
class DeadlineCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private function holiday(string $date, string $name = 'Holiday'): void
    {
        HolidayCalendar::create(['date' => $date, 'name' => $name, 'type' => 'regular']);
    }

    private function due(string $trigger, int $days, string $type = 'calendar'): string
    {
        return (new DeadlineCalculator)->calculate(Carbon::parse($trigger), $days, $type)->format('Y-m-d');
    }

    public function test_basic_calendar_days_calculation_no_holidays_or_weekends_involved(): void
    {
        $this->assertSame('2026-07-09', $this->due('2026-07-06', 3)); // Mon + 3 = Thu
    }

    public function test_calendar_days_lands_on_weekend_shifts_to_monday(): void
    {
        $this->assertSame('2026-07-13', $this->due('2026-07-08', 3)); // Wed + 3 = Sat -> Mon
    }

    public function test_calendar_days_lands_on_holiday_shifts_to_next_working_day(): void
    {
        $this->holiday('2026-07-09');

        $this->assertSame('2026-07-10', $this->due('2026-07-06', 3));
    }

    public function test_calendar_days_lands_on_holiday_friday_shifts_to_monday(): void
    {
        $this->holiday('2026-07-10');

        $this->assertSame('2026-07-13', $this->due('2026-07-07', 3));
    }

    public function test_holidays_and_weekends_inside_the_period_do_not_extend_it(): void
    {
        $this->holiday('2026-07-08');

        // Mon 07-06 + 15 = Tue 07-21; the mid-period holiday and weekends still count.
        $this->assertSame('2026-07-21', $this->due('2026-07-06', 15));
    }

    public function test_consecutive_non_working_days_are_all_skipped(): void
    {
        // Holy Week 2026: Maundy Thursday 04-02, Good Friday 04-03, Black Saturday 04-04.
        $this->holiday('2026-04-02', 'Maundy Thursday');
        $this->holiday('2026-04-03', 'Good Friday');
        $this->holiday('2026-04-04', 'Black Saturday');

        $computation = (new DeadlineCalculator)->compute(Carbon::parse('2026-03-18'), 15); // nominal Thu 04-02

        $this->assertSame('2026-04-06', $computation->dueDate->toDateString());
        $this->assertSame(
            ['Maundy Thursday', 'Good Friday', 'Black Saturday', 'Sunday'],
            array_column($computation->adjustments, 'reason'),
        );
    }

    public function test_working_days_calculation_skips_weekends(): void
    {
        $this->assertSame('2026-07-14', $this->due('2026-07-09', 3, 'working_days')); // Thu -> Fri, Mon, Tue
    }

    public function test_working_days_calculation_skips_holidays(): void
    {
        $this->holiday('2026-07-10');

        $this->assertSame('2026-07-14', $this->due('2026-07-08', 3, 'working_days'));
    }

    /**
     * Year-end: Rizal Day (12-30), Last Day of the Year (12-31) and New Year (01-01).
     */
    #[DataProvider('yearEndCases')]
    public function test_year_end_holidays(string $trigger, int $days, string $expected): void
    {
        $this->holiday('2026-12-30');
        $this->holiday('2026-12-31');
        $this->holiday('2027-01-01');

        $this->assertSame($expected, $this->due($trigger, $days));
    }

    public static function yearEndCases(): array
    {
        return [
            'lands on Rizal Day' => ['2026-12-15', 15, '2027-01-04'],   // Wed 12-30 -> Mon 01-04
            'lands on New Year' => ['2026-12-17', 15, '2027-01-04'],    // Fri 01-01 -> Mon 01-04
            'clear of holidays' => ['2026-12-20', 15, '2027-01-04'],    // Mon 01-04
        ];
    }

    public function test_it_rejects_invalid_periods(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->due('2026-07-06', 0);
    }

    public function test_it_rejects_unknown_period_types(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->due('2026-07-06', 5, 'lunar');
    }
}
