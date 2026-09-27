<?php

namespace App\Domain\Matters\Services;

use App\Domain\Deadlines\Enums\DeadlineKind;
use App\Domain\Deadlines\Services\DeadlineScheduler;
use App\Domain\Matters\Models\CaseWorkflowTemplate;
use App\Domain\Matters\Models\Matter;
use App\Models\User;

/**
 * Applies a firm's standard checklist for a case type (e.g. an Annulment
 * always needs a psychological evaluation and a petition draft) as tasks on
 * a newly opened matter.
 */
class WorkflowEngine
{
    public function __construct(private readonly DeadlineScheduler $scheduler) {}

    /** Apply every active template for the matter's case type. Returns tasks created. */
    public function applyFor(Matter $matter, User $by): int
    {
        $templates = CaseWorkflowTemplate::query()
            ->where('firm_id', $matter->firm_id)
            ->where('case_type', $matter->case_type)
            ->where('is_active', true)
            ->get();

        return $templates->sum(fn (CaseWorkflowTemplate $template) => $this->apply($matter, $template, $by));
    }

    public function apply(Matter $matter, CaseWorkflowTemplate $template, User $by): int
    {
        $start = $matter->opened_at->toImmutable();

        foreach ($template->tasks as $task) {
            $this->scheduler->scheduleManual($matter, $by, [
                'kind' => DeadlineKind::tryFrom($task['kind'] ?? '') ?? DeadlineKind::Task,
                'title' => $task['title'],
                'due_date' => $start->addDays((int) ($task['days_offset'] ?? 0)),
                'notes' => "From workflow: {$template->name}",
            ]);
        }

        return count($template->tasks);
    }
}
