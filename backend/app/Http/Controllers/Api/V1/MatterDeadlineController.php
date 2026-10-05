<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Deadlines\ClientHearingNotices;
use App\Domain\Deadlines\Enums\DeadlineKind;
use App\Domain\Deadlines\Enums\DeadlineStatus;
use App\Domain\Deadlines\Enums\TaskPriority;
use App\Domain\Deadlines\Models\DeadlineRule;
use App\Domain\Deadlines\Models\MatterDeadline;
use App\Domain\Deadlines\Services\DeadlineCalculator;
use App\Domain\Deadlines\Services\DeadlineScheduler;
use App\Domain\Deadlines\Services\RecurringTasks;
use App\Domain\Matters\Models\Matter;
use App\Http\Controllers\Controller;
use App\Http\Resources\DeadlineResource;
use App\Models\User;
use App\Notifications\WorkAssigned;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class MatterDeadlineController extends Controller
{
    public function __construct(private readonly DeadlineScheduler $scheduler) {}

    /**
     * Firm-wide deadlines in a date range, for the calendar and agenda views.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'from' => ['required_without:matter_id', 'date'],
            'to' => ['required_without:matter_id', 'date', 'after_or_equal:from'],
            'matter_id' => ['nullable', 'integer'],
            'status' => ['nullable', new Enum(DeadlineStatus::class)],
            'mine' => ['boolean'],
        ]);

        if (isset($validated['from'], $validated['to'])
            && CarbonImmutable::parse($validated['from'])->diffInDays(CarbonImmutable::parse($validated['to'])) > 400) {
            abort(422, 'Date range may not exceed 400 days.');
        }

        $deadlines = MatterDeadline::query()
            ->with(['matter', 'assignee'])
            ->when(isset($validated['from']), fn ($q) => $q->whereBetween('due_date', [$validated['from'], $validated['to']]))
            ->when($validated['matter_id'] ?? null, fn ($q, $id) => $q->where('matter_id', $id))
            ->when($validated['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($request->boolean('mine'), fn ($q) => $q->where('assigned_to', $request->user()->id))
            ->orderBy('due_date')
            ->orderBy('due_time')
            ->limit(1000)
            ->get();

        return DeadlineResource::collection($deadlines);
    }

    public function forMatter(Matter $matter): AnonymousResourceCollection
    {
        return DeadlineResource::collection(
            $matter->deadlines()->with(['assignee', 'rule'])->orderBy('due_date')->get()
        );
    }

    public function show(MatterDeadline $deadline): DeadlineResource
    {
        return new DeadlineResource($deadline->load(['matter', 'assignee', 'rule', 'events.user']));
    }

    /**
     * Create a deadline on a matter: either from a reglementary rule and its
     * trigger date (the due date is computed), or with an explicit due date.
     */
    public function store(Request $request, Matter $matter): JsonResponse
    {
        Gate::authorize('work-matters');

        $validated = $request->validate([
            'deadline_rule_id' => ['nullable', 'integer'],
            'trigger_date' => ['required_with:deadline_rule_id', 'nullable', 'date'],
            'kind' => ['required_without:deadline_rule_id', new Enum(DeadlineKind::class)],
            'title' => ['required_without:deadline_rule_id', 'nullable', 'string', 'max:255'],
            'due_date' => ['required_without:deadline_rule_id', 'nullable', 'date'],
            'due_time' => ['nullable', 'date_format:H:i'],
            'location' => ['nullable', 'string', 'max:255'],
            'assigned_to' => ['nullable', 'integer', Rule::exists('users', 'id')->where('firm_id', $matter->firm_id)],
            'notes' => ['nullable', 'string', 'max:5000'],
            'priority' => ['nullable', new Enum(TaskPriority::class)],
            'notify_client' => ['boolean'],
            // Tasks only: finishing one creates the next.
            'repeat' => ['nullable', Rule::in(array_keys(RecurringTasks::REPEATS)), Rule::prohibitedIf(fn () => $request->input('kind') !== DeadlineKind::Task->value)],
            'repeat_until' => ['nullable', 'date', 'after_or_equal:due_date'],
        ]);

        $attributes = collect($validated)->only(['due_time', 'location', 'assigned_to', 'notes', 'priority', 'notify_client', 'repeat', 'repeat_until'])->filter(fn ($v) => $v !== null)->all();

        if (isset($validated['deadline_rule_id'])) {
            $rule = DeadlineRule::availableTo($matter->firm_id)->where('is_active', true)->findOrFail($validated['deadline_rule_id']);

            if (! empty($validated['title'])) {
                $attributes['title'] = $validated['title'];
            }

            $deadline = $this->scheduler->scheduleFromRule($matter, $rule, CarbonImmutable::parse($validated['trigger_date']), $request->user(), $attributes);
        } else {
            $deadline = $this->scheduler->scheduleManual($matter, $request->user(), [
                ...$attributes,
                'kind' => $validated['kind'],
                'title' => $validated['title'],
                'due_date' => $validated['due_date'],
            ]);
        }

        $this->tellAssignee($deadline, $request->user());

        return (new DeadlineResource($deadline->load(['matter', 'assignee', 'rule'])))->response()->setStatusCode(201);
    }

    public function update(Request $request, MatterDeadline $deadline): DeadlineResource
    {
        Gate::authorize('work-matters');

        $deadline->update($request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'due_time' => ['sometimes', 'nullable', 'date_format:H:i'],
            'location' => ['sometimes', 'nullable', 'string', 'max:255'],
            'assigned_to' => ['sometimes', 'nullable', 'integer', Rule::exists('users', 'id')->where('firm_id', $deadline->firm_id)],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'priority' => ['sometimes', new Enum(TaskPriority::class)],
            'notify_client' => ['sometimes', 'boolean'],
            'repeat' => ['sometimes', 'nullable', Rule::in(array_keys(RecurringTasks::REPEATS)), Rule::prohibitedIf(fn () => $deadline->kind !== DeadlineKind::Task)],
            'repeat_until' => ['sometimes', 'nullable', 'date', 'after_or_equal:'.$deadline->due_date->toDateString()],
        ]));

        if ($deadline->wasChanged('assigned_to')) {
            $this->tellAssignee($deadline, $request->user());
        }
        // A hearing at another time is a move, for the client too.
        if ($deadline->wasChanged('due_time') && $deadline->status === DeadlineStatus::Pending) {
            app(ClientHearingNotices::class)->moved($deadline, $deadline->due_date->toDateString());
        }

        return new DeadlineResource($deadline->load(['matter', 'assignee', 'rule']));
    }

    /** The new assignee hears about it, unless they assigned it to themselves. */
    private function tellAssignee(MatterDeadline $deadline, User $by): void
    {
        if ($deadline->assigned_to && $deadline->assigned_to !== $by->id) {
            User::find($deadline->assigned_to)?->notify(new WorkAssigned($deadline, $by));
        }
    }

    public function complete(Request $request, MatterDeadline $deadline): DeadlineResource
    {
        Gate::authorize('work-matters');

        $validated = $request->validate(['notes' => ['nullable', 'string', 'max:2000']]);
        $this->scheduler->complete($deadline, $request->user(), $validated['notes'] ?? null);

        return new DeadlineResource($deadline->load(['matter', 'assignee', 'rule']));
    }

    public function cancel(Request $request, MatterDeadline $deadline): DeadlineResource
    {
        Gate::authorize('practice-law');

        $validated = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $this->scheduler->cancel($deadline, $request->user(), $validated['reason']);

        return new DeadlineResource($deadline->load(['matter', 'assignee', 'rule']));
    }

    public function reschedule(Request $request, MatterDeadline $deadline): DeadlineResource
    {
        Gate::authorize('practice-law');

        $validated = $request->validate([
            'due_date' => ['required', 'date'],
            'reason' => ['required', 'string', 'max:2000'],
        ]);
        $this->scheduler->reschedule($deadline, CarbonImmutable::parse($validated['due_date']), $request->user(), $validated['reason']);

        return new DeadlineResource($deadline->load(['matter', 'assignee', 'rule']));
    }

    /**
     * Preview a computed due date without saving anything.
     */
    public function compute(Request $request, DeadlineCalculator $calculator): JsonResponse
    {
        $validated = $request->validate([
            'trigger_date' => ['required', 'date'],
            'deadline_rule_id' => ['required_without:period_days', 'nullable', 'integer'],
            'period_days' => ['required_without:deadline_rule_id', 'nullable', 'integer', 'min:1', 'max:3650'],
            'period_type' => ['nullable', Rule::in(['calendar', 'working_days'])],
        ]);

        if (isset($validated['deadline_rule_id'])) {
            $rule = DeadlineRule::availableTo($request->user()->firm_id)->findOrFail($validated['deadline_rule_id']);
            [$days, $type] = [$rule->period_days, $rule->period_type];
        } else {
            [$days, $type] = [(int) $validated['period_days'], $validated['period_type'] ?? 'calendar'];
        }

        return response()->json($calculator->compute(CarbonImmutable::parse($validated['trigger_date']), $days, $type)->toArray());
    }
}
