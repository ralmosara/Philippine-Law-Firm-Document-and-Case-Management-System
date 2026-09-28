<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Business\Models\Prospect;
use App\Domain\Business\Models\ProspectEvent;
use App\Domain\Business\Pipeline;
use App\Domain\Compliance\Models\ConflictCheck;
use App\Domain\Intake\Models\IntakeRequest;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** Business development: the prospects pipeline and its conversion report. */
class ProspectController extends Controller
{
    public function __construct(private readonly Pipeline $pipeline) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('work-matters');
        $validated = $request->validate([
            'owner_id' => ['nullable', 'integer'],
            'closed' => ['nullable', 'boolean'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);
        $search = mb_strtolower(trim((string) ($validated['search'] ?? '')));

        $prospects = Prospect::query()
            ->with('owner:id,name')
            ->when(! ($validated['closed'] ?? false), fn ($q) => $q->whereIn('stage', Prospect::OPEN), fn ($q) => $q->whereNotIn('stage', Prospect::OPEN)->where('closed_at', '>=', now()->subYear()))
            ->when($validated['owner_id'] ?? null, fn ($q, $id) => $q->where('owner_id', $id))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->whereRaw('lower(name) like ?', ["%{$search}%"])->orWhereRaw('lower(organization) like ?', ["%{$search}%"])))
            ->orderByRaw('next_step_on is null')->orderBy('next_step_on')->latest('id')
            ->limit(500)->get();

        return response()->json([
            'stages' => Prospect::STAGES,
            'sources' => Prospect::SOURCES,
            'data' => $prospects->map(fn (Prospect $p) => $this->present($p)),
        ]);
    }

    public function show(Prospect $prospect): JsonResponse
    {
        Gate::authorize('work-matters');

        return response()->json([
            ...$this->present($prospect->load(['owner:id,name', 'matter:id,reference,title'])),
            'conflicts' => $prospect->conflictChecks()->map(fn (ConflictCheck $c) => ['id' => $c->id, 'search_term' => $c->search_term, 'status' => $c->status->value, 'match_count' => $c->match_count]),
            'events' => $prospect->events()->with('author:id,name')->latest('id')->limit(200)->get()->map(fn (ProspectEvent $e) => [
                'id' => $e->id, 'type' => $e->type, 'from_stage' => $e->from_stage, 'to_stage' => $e->to_stage,
                'to_label' => $e->to_stage ? (Prospect::STAGES[$e->to_stage] ?? $e->to_stage) : null,
                'body' => $e->body, 'by' => $e->author?->name, 'at' => $e->created_at?->toIso8601String(),
            ]),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('work-matters');
        $prospect = $this->pipeline->create($request->validate($this->rules($request, true)), $request->user());

        return response()->json($this->present($prospect->load('owner:id,name')), 201);
    }

    public function update(Request $request, Prospect $prospect): JsonResponse
    {
        Gate::authorize('work-matters');
        $validated = $request->validate($this->rules($request, false));
        if (array_key_exists('opposing_parties', $validated)) {
            $validated['opposing_parties'] = array_values(array_filter(array_map('trim', $validated['opposing_parties'] ?? [])));
        }
        $prospect->update($validated);
        if ($prospect->wasChanged(['name', 'opposing_parties'])) {
            $this->pipeline->recheck($prospect, $request->user());
        }

        return response()->json($this->present($prospect->load('owner:id,name')));
    }

    public function move(Request $request, Prospect $prospect): JsonResponse
    {
        Gate::authorize('work-matters');
        $validated = $request->validate([
            'stage' => ['required', Rule::in(array_keys(Prospect::STAGES))],
            'note' => ['nullable', 'string', 'max:5000'],
            'lost_reason' => ['nullable', 'string', 'max:500'],
        ]);
        $this->pipeline->move($prospect, $validated['stage'], $request->user(), $validated['note'] ?? null, $validated['lost_reason'] ?? null);

        return response()->json($this->present($prospect->refresh()->load('owner:id,name')));
    }

    public function note(Request $request, Prospect $prospect): JsonResponse
    {
        Gate::authorize('work-matters');
        $validated = $request->validate(['type' => ['required', Rule::in(['note', 'call', 'meeting', 'email'])], 'body' => ['required', 'string', 'max:5000']]);
        $this->pipeline->note($prospect, $validated['type'], $validated['body'], $request->user());

        return response()->json(['ok' => true], 201);
    }

    public function convert(Request $request, Prospect $prospect): JsonResponse
    {
        Gate::authorize('practice-law');
        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'responsible_lawyer_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('firm_id', $request->user()->firm_id)],
        ]);
        $matter = $this->pipeline->convert($prospect, $request->user(), $validated['title'] ?? null, $validated['responsible_lawyer_id'] ?? null);

        return response()->json(['matter_id' => $matter->id, 'reference' => $matter->reference], 201);
    }

    public function fromIntake(Request $request, IntakeRequest $intakeRequest): JsonResponse
    {
        Gate::authorize('work-matters');
        $prospect = $this->pipeline->fromIntake($intakeRequest, $request->user());

        return response()->json($this->present($prospect->load('owner:id,name')), $prospect->wasRecentlyCreated ? 201 : 200);
    }

    public function report(Request $request): JsonResponse
    {
        Gate::authorize('manage-finances');
        $validated = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);
        $to = isset($validated['to']) ? CarbonImmutable::parse($validated['to']) : CarbonImmutable::today();
        $from = isset($validated['from']) ? CarbonImmutable::parse($validated['from']) : $to->subYear()->addDay();

        return response()->json($this->pipeline->report($from, $to));
    }

    private function rules(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:255'],
            'organization' => ['nullable', 'string', 'max:255'],
            'client_type' => [$required, Rule::in(['individual', 'corporate'])],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'source' => [$required, Rule::in(array_keys(Prospect::SOURCES))],
            'referred_by' => ['nullable', 'string', 'max:255'],
            'case_type' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:10000'],
            'opposing_parties' => ['nullable', 'array', 'max:20'],
            'opposing_parties.*' => ['string', 'max:255'],
            'estimated_value_cents' => ['nullable', 'integer', 'min:0', 'max:2000000000'],
            'owner_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('firm_id', $request->user()->firm_id)],
            'next_step' => ['nullable', 'string', 'max:255'],
            'next_step_on' => ['nullable', 'date'],
        ];
    }

    private function present(Prospect $p): array
    {
        return [
            'id' => $p->id,
            'name' => $p->name,
            'organization' => $p->organization,
            'client_type' => $p->client_type,
            'email' => $p->email,
            'phone' => $p->phone,
            'source' => $p->source,
            'source_label' => Prospect::SOURCES[$p->source] ?? $p->source,
            'referred_by' => $p->referred_by,
            'case_type' => $p->case_type,
            'description' => $p->description,
            'opposing_parties' => $p->opposing_parties ?? [],
            'estimated_value_cents' => $p->estimated_value_cents,
            'stage' => $p->stage,
            'stage_label' => Prospect::STAGES[$p->stage] ?? $p->stage,
            'owner_id' => $p->owner_id,
            'owner' => $p->relationLoaded('owner') ? $p->owner?->name : null,
            'next_step' => $p->next_step,
            'next_step_on' => $p->next_step_on?->toDateString(),
            'follow_up_due' => $p->isOpen() && $p->next_step_on && $p->next_step_on->lte(today()),
            'proposal_sent_on' => $p->proposal_sent_on?->toDateString(),
            'engagement_sent_on' => $p->engagement_sent_on?->toDateString(),
            'engagement_signed_on' => $p->engagement_signed_on?->toDateString(),
            'lost_reason' => $p->lost_reason,
            'intake_request_id' => $p->intake_request_id,
            'matter' => $p->relationLoaded('matter') && $p->matter ? ['id' => $p->matter->id, 'reference' => $p->matter->reference, 'title' => $p->matter->title] : null,
            'created_at' => $p->created_at?->toIso8601String(),
        ];
    }
}
