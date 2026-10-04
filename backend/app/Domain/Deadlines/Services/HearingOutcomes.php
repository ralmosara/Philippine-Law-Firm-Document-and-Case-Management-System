<?php

namespace App\Domain\Deadlines\Services;

use App\Domain\Billing\Models\TimeEntry;
use App\Domain\Deadlines\Enums\DeadlineKind;
use App\Domain\Deadlines\Enums\DeadlineStatus;
use App\Domain\Deadlines\Models\MatterDeadline;
use App\Domain\Matters\Models\Matter;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Recording what happened at a hearing, from the courtroom: the result, the
 * next setting, the deadlines the court gave, and the time spent. One step,
 * so nothing ordered in open court is left to memory.
 */
class HearingOutcomes
{
    public const HELD = 'held';

    public const RESET = 'reset';          // postponed or reset to another date

    public const CANCELLED = 'cancelled';  // taken off the calendar, no new date

    public function __construct(
        private readonly DeadlineScheduler $scheduler,
        private readonly DeadlineCalculator $calculator,
    ) {}

    /**
     * @param  array{outcome: string, notes?: ?string, next_date?: ?string, next_time?: ?string, next_location?: ?string,
     *               follow_ups?: list<array{title: string, days: int, kind?: string}>, minutes?: ?int}  $data
     * @return array{hearing: MatterDeadline, next: ?MatterDeadline, follow_ups: list<MatterDeadline>, time_entry: ?TimeEntry}
     */
    public function record(MatterDeadline $hearing, array $data, User $by): array
    {
        if ($hearing->kind !== DeadlineKind::Hearing) {
            throw ValidationException::withMessages(['deadline' => 'Only hearings have an outcome.']);
        }
        if ($hearing->status !== DeadlineStatus::Pending) {
            throw ValidationException::withMessages(['deadline' => 'This hearing has already been recorded.']);
        }
        if ($data['outcome'] === self::RESET && empty($data['next_date'])) {
            throw ValidationException::withMessages(['next_date' => 'Give the new date of the hearing.']);
        }

        return DB::transaction(function () use ($hearing, $data, $by) {
            $matter = Matter::findOrFail($hearing->matter_id);
            $notes = trim((string) ($data['notes'] ?? '')) ?: null;
            $heardOn = $hearing->due_date->format('F j, Y');
            $next = null;

            switch ($data['outcome']) {
                case self::RESET:
                    // Same hearing, new date: its history shows the postponement.
                    $hearing = $this->scheduler->reschedule($hearing, CarbonImmutable::parse($data['next_date']), $by, $notes ?? 'Hearing reset in open court');
                    $hearing->forceFill(array_filter([
                        'due_time' => $data['next_time'] ?? null,
                        'location' => $data['next_location'] ?? null,
                    ]))->save();
                    $next = $hearing;
                    break;

                case self::CANCELLED:
                    $hearing = $this->scheduler->cancel($hearing, $by, $notes ?? 'Hearing cancelled');
                    break;

                default:
                    $hearing = $this->scheduler->complete($hearing, $by, $notes);
                    if (! empty($data['next_date'])) {
                        $next = $this->scheduler->scheduleManual($matter, $by, array_filter([
                            'kind' => DeadlineKind::Hearing,
                            'title' => $data['next_title'] ?? 'Continuation of hearing',
                            'due_date' => $data['next_date'],
                            'due_time' => $data['next_time'] ?? null,
                            'location' => ($data['next_location'] ?? null) ?: $hearing->location,
                            'assigned_to' => $hearing->assigned_to,
                        ], fn ($v) => $v !== null));
                    }
            }

            // "Parties are given 30 days to file memoranda": an order given in open
            // court runs from the hearing, not from when someone records it (a phone
            // may send this a day later, when it finds a signal). Moved to the next
            // working day under Rule 22 if it falls on a holiday.
            $orderedOn = CarbonImmutable::parse($hearing->due_date)->min(CarbonImmutable::today());
            $followUps = [];
            foreach ($data['follow_ups'] ?? [] as $item) {
                $followUps[] = $this->scheduler->scheduleManual($matter, $by, [
                    'kind' => DeadlineKind::tryFrom($item['kind'] ?? 'filing') ?? DeadlineKind::Filing,
                    'title' => $item['title'],
                    'due_date' => $this->calculator->calculate($orderedOn, (int) $item['days'])->toDateString(),
                    'assigned_to' => $hearing->assigned_to,
                    'notes' => "Ordered at the hearing of {$heardOn}.",
                ]);
            }

            $entry = null;
            if (! empty($data['minutes'])) {
                $entry = TimeEntry::create([
                    'firm_id' => $matter->firm_id,
                    'matter_id' => $matter->id,
                    'user_id' => $by->id,
                    'work_date' => $orderedOn->toDateString(),
                    'minutes' => (int) $data['minutes'],
                    'description' => 'Appearance: '.$hearing->title.($notes ? " ({$notes})" : ''),
                    'is_billable' => true,
                    'rate_cents' => $by->hourly_rate_cents,
                ]);
            }

            return ['hearing' => $hearing->refresh(), 'next' => $next, 'follow_ups' => $followUps, 'time_entry' => $entry];
        });
    }
}
