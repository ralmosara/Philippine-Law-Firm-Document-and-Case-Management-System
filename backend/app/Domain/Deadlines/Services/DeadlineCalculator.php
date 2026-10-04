<?php

namespace App\Domain\Deadlines\Services;

use App\Domain\Deadlines\Models\HolidayCalendar;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * Computes reglementary due dates under Rule 22, Section 1 of the Rules of
 * Court: exclude the first day, include the last, and if the last day falls
 * on a Saturday, Sunday or legal holiday, the period runs until the next
 * working day.
 *
 * `working_days` periods (used by some administrative and labor rules)
 * count only working days.
 */
class DeadlineCalculator
{
    /** @var array<string, string>|null date (Y-m-d) => holiday name, loaded lazily */
    private ?array $holidays = null;

    public function calculate(CarbonInterface $triggerDate, int $periodDays, string $periodType = 'calendar'): CarbonImmutable
    {
        return $this->compute($triggerDate, $periodDays, $periodType)->dueDate;
    }

    /**
     * Compute the due date along with the reasons it moved, so users can see
     * why a deadline lands where it does.
     */
    public function compute(CarbonInterface $triggerDate, int $periodDays, string $periodType = 'calendar'): DeadlineComputation
    {
        if ($periodDays < 1) {
            throw new InvalidArgumentException('A reglementary period must be at least one day.');
        }

        $date = CarbonImmutable::parse($triggerDate->format('Y-m-d'));

        if ($periodType === 'working_days') {
            for ($counted = 0; $counted < $periodDays;) {
                $date = $date->addDay();
                if ($this->nonWorkingReason($date) === null) {
                    $counted++;
                }
            }

            return new DeadlineComputation($date, $date, []);
        }

        if ($periodType !== 'calendar') {
            throw new InvalidArgumentException("Unknown period type [{$periodType}].");
        }

        // addDays() naturally excludes the trigger day and includes the last.
        $nominal = $date->addDays($periodDays);
        $due = $nominal;
        $adjustments = [];

        while (($reason = $this->nonWorkingReason($due)) !== null) {
            $adjustments[] = ['date' => $due->toDateString(), 'reason' => $reason];
            $due = $due->addDay();
        }

        return new DeadlineComputation($due, $nominal, $adjustments);
    }

    /**
     * A period that ends on a given day (e.g. counted in years or months):
     * moved past a Saturday, Sunday or holiday under Rule 22, with the reasons.
     */
    public function rollForward(CarbonInterface $nominalLastDay): DeadlineComputation
    {
        $nominal = CarbonImmutable::parse($nominalLastDay->format('Y-m-d'));
        $due = $nominal;
        $adjustments = [];

        while (($reason = $this->nonWorkingReason($due)) !== null) {
            $adjustments[] = ['date' => $due->toDateString(), 'reason' => $reason];
            $due = $due->addDay();
        }

        return new DeadlineComputation($due, $nominal, $adjustments);
    }

    public function isWorkingDay(CarbonInterface $date): bool
    {
        return $this->nonWorkingReason($date) === null;
    }

    /** Why a date is not a working day (holiday name first, as it is more informative), or null. */
    private function nonWorkingReason(CarbonInterface $date): ?string
    {
        if ($holiday = $this->holidays()[$date->format('Y-m-d')] ?? null) {
            return $holiday;
        }

        if ($date->isWeekend()) {
            return $date->isSaturday() ? 'Saturday' : 'Sunday';
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    private function holidays(): array
    {
        return $this->holidays ??= HolidayCalendar::query()
            ->get(['date', 'name'])
            ->mapWithKeys(fn (HolidayCalendar $holiday) => [$holiday->date->format('Y-m-d') => $holiday->name])
            ->all();
    }
}
