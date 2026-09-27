<?php

namespace App\Domain\Imports\Importers;

use App\Domain\Deadlines\Enums\DeadlineKind;
use App\Domain\Deadlines\Enums\DeadlineStatus;
use App\Domain\Deadlines\Models\MatterDeadline;
use App\Domain\Deadlines\Services\DeadlineScheduler;
use App\Domain\Imports\Values;
use App\Domain\Matters\Models\Matter;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Upcoming deadlines and hearings of existing matters. Reminders go out for
 * them like any other deadline. Past dates are refused: the system would
 * only mark them missed.
 */
class DeadlineImporter extends Importer
{
    /** @var array<string, true> matter id|title|date of deadlines already in the system */
    private array $existing = [];

    public function label(): string
    {
        return 'Deadlines and hearings';
    }

    public function ability(): string
    {
        return 'manage-firm';
    }

    public function modelClass(): string
    {
        return MatterDeadline::class;
    }

    public function prepare(int $firmId): void
    {
        parent::prepare($firmId);
        $this->existing = [];
        MatterDeadline::query()->where('status', '!=', DeadlineStatus::Cancelled->value)
            ->whereDate('due_date', '>=', today())
            ->get(['matter_id', 'title', 'due_date'])
            ->each(fn (MatterDeadline $d) => $this->existing[$this->key($d->matter_id, $d->title, $d->due_date->toDateString())] = true);
    }

    public function columns(): array
    {
        return [
            'matter' => ['label' => 'Matter', 'required' => true, 'aliases' => ['matter reference', 'reference', 'case number', 'case no', 'docket', 'docket no'], 'example' => 'M-2026-0001', 'hint' => "The matter's reference or its case number."],
            'title' => ['label' => 'Title', 'required' => true, 'aliases' => ['deadline', 'description', 'event', 'what', 'particulars'], 'example' => 'File pre-trial brief'],
            'due_date' => ['label' => 'Due date', 'required' => true, 'aliases' => ['date', 'due', 'deadline date', 'hearing date', 'schedule'], 'example' => '10/15/2026', 'hint' => 'Month first: 10/15/2026, or 2026-10-15. Today or later.'],
            'kind' => ['label' => 'Kind', 'aliases' => ['type', 'category'], 'example' => 'filing', 'hint' => 'filing, hearing or task. Blank: hearing when the title mentions a hearing, else filing.'],
            'due_time' => ['label' => 'Time', 'aliases' => ['due time', 'hearing time'], 'example' => '8:30 AM'],
            'location' => ['label' => 'Location', 'aliases' => ['venue', 'courtroom', 'place', 'sala'], 'example' => 'RTC Manila Branch 21, Room 305'],
            'assigned_to' => ['label' => 'Assigned to', 'aliases' => ['lawyer', 'assignee', 'handling lawyer', 'responsible'], 'example' => 'ceo@demofirm.ph', 'hint' => "E-mail or full name. Blank: the matter's responsible lawyer."],
            'notes' => ['label' => 'Notes', 'aliases' => ['remarks'], 'example' => 'Bring original contracts'],
        ];
    }

    public function check(array $row): array
    {
        $errors = [];

        $matter = null;
        if (trim($row['matter'] ?? '') === '') {
            $errors[] = 'Matter is required.';
        } else {
            $matter = $this->findMatter($row['matter'], $error);
            if ($error) {
                $errors[] = $error;
            }
        }

        $title = trim($row['title'] ?? '');
        if ($title === '') {
            $errors[] = 'Title is required.';
        }

        $due = Values::date($row['due_date'] ?? '');
        if (trim($row['due_date'] ?? '') === '') {
            $errors[] = 'Due date is required.';
        } elseif ($due === null) {
            $errors[] = "Due date \"{$row['due_date']}\" is not a date. Use 10/15/2026 (month first) or 2026-10-15.";
        } elseif ($due->lt(CarbonImmutable::today())) {
            $errors[] = "Due date {$due->toFormattedDateString()} has passed.";
        }

        $kindText = mb_strtolower(trim($row['kind'] ?? ''));
        // "File pre-trial brief" is a filing even though it mentions a trial.
        $kind = match (true) {
            $kindText !== '' => DeadlineKind::tryFrom($kindText),
            (bool) preg_match('/\b(file|filing|submit|serve|brief|memorandum|motion|comment|reply|answer|appeal|petition|pleading|position paper|formal offer)\b/i', $title) => DeadlineKind::Filing,
            (bool) preg_match('/\b(hearing|trial|arraignment|mediation|conference|promulgation|appearance)\b/i', $title) => DeadlineKind::Hearing,
            default => DeadlineKind::Filing,
        };
        if ($kind === null) {
            $errors[] = "Kind \"{$row['kind']}\" should be filing, hearing or task.";
        }

        $time = null;
        if (trim($row['due_time'] ?? '') !== '') {
            $time = Values::time($row['due_time']);
            if ($time === null) {
                $errors[] = "Time \"{$row['due_time']}\" should look like 8:30 AM or 13:30.";
            }
        }

        $assignee = null;
        if (trim($row['assigned_to'] ?? '') !== '') {
            $assignee = $this->findUser($row['assigned_to'], $error);
            if ($error) {
                $errors[] = $error;
            }
        }

        $values = [
            'matter_id' => $matter?->id,
            'matter_reference' => $matter?->reference,
            'title' => mb_substr($title, 0, 255),
            'due_date' => $due?->toDateString(),
            'kind' => $kind?->value,
            'due_time' => $time,
            'location' => mb_substr(trim($row['location'] ?? ''), 0, 255) ?: null,
            'assigned_to' => $assignee?->id,
            'notes' => trim($row['notes'] ?? '') ?: null,
        ];

        if ($errors !== []) {
            return $this->result($values, $errors);
        }

        $key = $this->key($matter->id, $values['title'], $values['due_date']);
        $duplicate = match (true) {
            isset($this->existing[$key]) => "{$matter->reference} already has \"{$values['title']}\" on that date.",
            $this->seenInFile($key) => 'The same deadline appears earlier in this file.',
            default => null,
        };

        return $this->result($values, duplicate: $duplicate);
    }

    private function key(int $matterId, string $title, string $date): string
    {
        return $matterId.'|'.Values::nameKey($title).'|'.$date;
    }

    public function create(array $values, User $by): Model
    {
        return app(DeadlineScheduler::class)->scheduleManual(Matter::findOrFail($values['matter_id']), $by, array_filter([
            'kind' => $values['kind'],
            'title' => $values['title'],
            'due_date' => $values['due_date'],
            'due_time' => $values['due_time'],
            'location' => $values['location'],
            'assigned_to' => $values['assigned_to'],
            'notes' => $values['notes'],
        ], fn ($v) => $v !== null));
    }

    public function undo(Model $model, User $by): ?string
    {
        /** @var MatterDeadline $model */
        if ($model->status !== DeadlineStatus::Pending || $model->events()->count() > 1) {
            return "\"{$model->title}\" has been worked on since the import.";
        }

        $model->delete();

        return null;
    }
}
