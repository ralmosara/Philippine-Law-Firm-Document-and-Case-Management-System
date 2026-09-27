<?php

namespace App\Domain\Deadlines\Enums;

/**
 * Escalating reminder stages. Ordered: a deadline only ever moves forward
 * through these, which is what makes the reminder dispatcher idempotent.
 */
enum ReminderStage: string
{
    case SevenDays = '7d';
    case ThreeDays = '72h';
    case OneDay = '24h';
    case DayOf = 'day_of';

    /** Number of days before the due date at which this stage fires. */
    public function daysBefore(): int
    {
        return match ($this) {
            self::SevenDays => 7,
            self::ThreeDays => 3,
            self::OneDay => 1,
            self::DayOf => 0,
        };
    }

    public function rank(): int
    {
        return match ($this) {
            self::SevenDays => 1,
            self::ThreeDays => 2,
            self::OneDay => 3,
            self::DayOf => 4,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::SevenDays => 'due in 7 days',
            self::ThreeDays => 'due in 3 days',
            self::OneDay => 'due tomorrow',
            self::DayOf => 'due today',
        };
    }

    /**
     * The most urgent stage that applies for a deadline this many days out,
     * or null if it is not yet within any reminder window.
     */
    public static function forDaysRemaining(int $days): ?self
    {
        foreach ([self::DayOf, self::OneDay, self::ThreeDays, self::SevenDays] as $stage) {
            if ($days <= $stage->daysBefore()) {
                return $stage;
            }
        }

        return null;
    }
}
