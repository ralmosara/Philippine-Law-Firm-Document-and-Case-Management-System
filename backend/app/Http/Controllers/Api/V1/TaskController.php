<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Deadlines\Enums\DeadlineKind;
use App\Domain\Deadlines\Enums\DeadlineStatus;
use App\Domain\Deadlines\Models\MatterDeadline;
use App\Domain\Deadlines\Services\DeadlineScheduler;
use App\Http\Controllers\Controller;
use App\Http\Resources\DeadlineResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** The task board: open tasks by column, plus the last two weeks' finished ones. */
class TaskController extends Controller
{
    /** Keeps the board responsive; older open tasks show up in matter views. */
    private const LIMIT = 500;

    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'matter_id' => ['nullable', 'integer'],
            'assignee' => ['nullable', 'string', 'regex:/^(me|unassigned|\d+)$/'],
        ]);

        $tasks = MatterDeadline::query()
            ->where('kind', DeadlineKind::Task->value)
            ->where(fn ($q) => $q
                ->whereIn('status', [DeadlineStatus::Pending->value, DeadlineStatus::Missed->value])
                ->orWhere(fn ($q) => $q->where('status', DeadlineStatus::Completed->value)->where('completed_at', '>=', now()->subDays(14))))
            ->when($validated['matter_id'] ?? null, fn ($q, $id) => $q->where('matter_id', $id))
            ->when($validated['assignee'] ?? null, fn ($q, $assignee) => match ($assignee) {
                'me' => $q->where('assigned_to', $request->user()->id),
                'unassigned' => $q->whereNull('assigned_to'),
                default => $q->where('assigned_to', (int) $assignee),
            })
            ->with(['matter', 'assignee'])
            ->orderBy('due_date')
            ->orderBy('id')
            ->limit(self::LIMIT)
            ->get();

        return DeadlineResource::collection($tasks);
    }

    public function move(Request $request, MatterDeadline $deadline, DeadlineScheduler $scheduler): DeadlineResource
    {
        Gate::authorize('work-matters');

        $validated = $request->validate(['column' => ['required', Rule::in(['todo', 'in_progress', 'review', 'done'])]]);
        $scheduler->moveTask($deadline, $validated['column'], $request->user());

        return new DeadlineResource($deadline->load(['matter', 'assignee']));
    }
}
