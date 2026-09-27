<?php

namespace App\Domain\Deadlines\Services;

use Carbon\CarbonImmutable;

final readonly class DeadlineComputation
{
    /**
     * @param  CarbonImmutable  $dueDate  The legally effective due date.
     * @param  CarbonImmutable  $nominalDate  Trigger date + period, before non-working-day adjustment.
     * @param  list<array{date: string, reason: string}>  $adjustments  Days skipped, in order.
     */
    public function __construct(
        public CarbonImmutable $dueDate,
        public CarbonImmutable $nominalDate,
        public array $adjustments,
    ) {}

    public function toArray(): array
    {
        return [
            'due_date' => $this->dueDate->toDateString(),
            'nominal_date' => $this->nominalDate->toDateString(),
            'adjustments' => $this->adjustments,
        ];
    }
}
