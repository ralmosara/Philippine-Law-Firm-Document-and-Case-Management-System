<?php

namespace App\Domain\Deadlines\Services;

use App\Domain\Deadlines\Enums\DeadlineKind;
use App\Domain\Deadlines\Enums\DeadlineStatus;
use App\Domain\Deadlines\Models\MatterDeadline;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * One lawyer, two hearings at once. Hearings clash when they are on the same
 * day for the same lawyer and less than two hours apart, or when either has
 * no time set (courts often call a whole morning's cases at 8:30).
 */
class HearingClashes
{
    public const MINUTES_APART = 120;

    /**
     * Pending hearings that would clash with one at $date/$time for $lawyerId.
     *
     * @return Collection<int, MatterDeadline>
     */
    public function forSlot(CarbonImmutable $date, ?string $time, int $lawyerId, ?int $exceptId = null): Collection
    {
        return $this->hearingsOn($date)
            ->filter(fn (MatterDeadline $h) => $this->lawyerOf($h) === $lawyerId && $h->id !== $exceptId && $this->overlap($time, $h->due_time))
            ->values();
    }

    /**
     * For each pending hearing on $date: the others the same lawyer has at the same time.
     *
     * @return array<int, list<MatterDeadline>>
     */
    public function onDay(CarbonImmutable $date): array
    {
        $clashes = [];
        foreach ($this->hearingsOn($date)->groupBy(fn (MatterDeadline $h) => $this->lawyerOf($h)) as $lawyer => $hearings) {
            if (! $lawyer) {
                continue;
            }
            foreach ($hearings as $a) {
                foreach ($hearings as $b) {
                    if ($a->id !== $b->id && $this->overlap($a->due_time, $b->due_time)) {
                        $clashes[$a->id][] = $b;
                    }
                }
            }
        }

        return $clashes;
    }

    /** @return array{id: int, title: string, time: ?string, matter: ?string, lawyer: ?string} */
    public function describe(MatterDeadline $h): array
    {
        return [
            'id' => $h->id,
            'title' => $h->title,
            'time' => $h->due_time ? substr($h->due_time, 0, 5) : null,
            'matter' => $h->matter ? "{$h->matter->reference} {$h->matter->title}" : null,
            'lawyer' => $h->assignee?->name ?? $h->matter?->responsibleLawyer?->name,
        ];
    }

    private function hearingsOn(CarbonImmutable $date): Collection
    {
        return MatterDeadline::query()
            ->where('kind', DeadlineKind::Hearing->value)
            ->where('status', DeadlineStatus::Pending->value)
            ->whereDate('due_date', $date->toDateString())
            ->with(['matter:id,reference,title,responsible_lawyer_id', 'matter.responsibleLawyer:id,name', 'assignee:id,name'])
            ->get();
    }

    private function lawyerOf(MatterDeadline $h): ?int
    {
        return $h->assigned_to ?? $h->matter?->responsible_lawyer_id;
    }

    private function overlap(?string $a, ?string $b): bool
    {
        if ($a === null || $b === null) {
            return true;
        }

        return abs($this->minutes($a) - $this->minutes($b)) < self::MINUTES_APART;
    }

    private function minutes(string $time): int
    {
        [$h, $m] = array_map('intval', explode(':', $time) + [0, 0]);

        return $h * 60 + $m;
    }
}
