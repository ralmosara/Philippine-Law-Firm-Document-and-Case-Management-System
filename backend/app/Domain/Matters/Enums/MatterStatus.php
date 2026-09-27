<?php

namespace App\Domain\Matters\Enums;

/**
 * Lifecycle of a litigated matter. Transitions are declared explicitly so the
 * model layer rejects illegal jumps (e.g. intake -> closed without a reason is
 * allowed, but filed -> intake is not).
 */
enum MatterStatus: string
{
    case Intake = 'intake';
    case Filed = 'filed';
    case PreTrial = 'pre_trial';
    case Trial = 'trial';
    case Decision = 'decision';
    case Appeal = 'appeal';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Intake => 'Intake',
            self::Filed => 'Filed',
            self::PreTrial => 'Pre-Trial',
            self::Trial => 'Trial',
            self::Decision => 'Decision',
            self::Appeal => 'Appeal',
            self::Closed => 'Closed',
        };
    }

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Intake => [self::Filed, self::Closed],
            self::Filed => [self::PreTrial, self::Decision, self::Closed],
            self::PreTrial => [self::Trial, self::Decision, self::Closed],
            self::Trial => [self::Decision, self::Closed],
            self::Decision => [self::Appeal, self::Closed],
            self::Appeal => [self::Decision, self::Closed],
            self::Closed => [self::Intake],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }

    public function isActive(): bool
    {
        return $this !== self::Closed;
    }

    /** Rough completion percentage for client-facing progress indicators. */
    public function progress(): int
    {
        return match ($this) {
            self::Intake => 10,
            self::Filed => 25,
            self::PreTrial => 45,
            self::Trial => 65,
            self::Decision => 85,
            self::Appeal => 90,
            self::Closed => 100,
        };
    }
}
