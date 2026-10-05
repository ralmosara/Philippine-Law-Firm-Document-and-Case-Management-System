<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Documents\Pleadings\PleadingAssembler;
use App\Domain\Matters\Actions\OpenMatter;
use App\Domain\Matters\Actions\TransitionMatterStatus;
use App\Domain\Matters\Enums\FeeArrangement;
use App\Domain\Matters\Enums\MatterStatus;
use App\Domain\Matters\Enums\PartyRole;
use App\Domain\Matters\Models\Matter;
use App\Http\Controllers\Controller;
use App\Http\Resources\MatterResource;
use App\Http\Resources\MatterStatusEventResource;
use App\Models\User;
use App\Notifications\WorkAssigned;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class MatterController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'status' => ['nullable', new Enum(MatterStatus::class)],
            'sort' => ['nullable', Rule::in(['opened_at', '-opened_at', 'reference', '-reference', 'title', '-title'])],
        ]);

        $sort = $request->query('sort', '-opened_at');

        $matters = Matter::query()
            ->with(['client', 'responsibleLawyer', 'nextDeadline'])
            ->when($request->query('search'), fn ($q, $search) => $q->where(fn ($q) => $q
                ->whereLike('title', "%{$search}%")
                ->orWhereLike('reference', "%{$search}%")
                ->orWhereLike('case_number', "%{$search}%")
                ->orWhereHas('client', fn ($q) => $q->whereLike('name', "%{$search}%"))))
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->boolean('active_only'), fn ($q) => $q->active())
            ->when($request->query('client_id'), fn ($q, $id) => $q->where('client_id', $id))
            ->when($request->query('lawyer_id'), fn ($q, $id) => $q->where('responsible_lawyer_id', $id))
            ->when($request->boolean('mine'), fn ($q) => $q->where('responsible_lawyer_id', $request->user()->id))
            ->orderBy(ltrim($sort, '-'), str_starts_with($sort, '-') ? 'desc' : 'asc')
            ->orderByDesc('id')
            ->paginate($this->perPage($request, 25));

        return MatterResource::collection($matters);
    }

    /** Lightweight list for pickers (time tracker, trust accounts, documents). */
    public function options(Request $request): JsonResponse
    {
        return response()->json(
            Matter::query()
                ->when($request->boolean('active_only', true), fn ($q) => $q->active())
                ->when($request->query('client_id'), fn ($q, $id) => $q->where('client_id', $id))
                ->orderByDesc('opened_at')
                ->limit(2000)
                ->get(['id', 'reference', 'title', 'client_id'])
        );
    }

    public function store(Request $request, OpenMatter $openMatter): JsonResponse
    {
        Gate::authorize('work-matters');

        $validated = $request->validate([
            ...$this->rules(),
            'parties' => ['array', 'max:50'],
            'parties.*.role' => ['required', new Enum(PartyRole::class)],
            'parties.*.name' => ['required', 'string', 'max:255'],
            'parties.*.counsel_name' => ['nullable', 'string', 'max:255'],
        ]);

        $parties = $validated['parties'] ?? [];
        unset($validated['parties']);

        $matter = $openMatter->execute($validated, $request->user(), $parties);
        $this->tellResponsibleLawyer($matter, $request->user());

        return (new MatterResource($matter->load(['client', 'responsibleLawyer', 'parties'])))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Matter $matter): MatterResource
    {
        $matter->load(['client', 'responsibleLawyer', 'parties', 'nextDeadline'])
            ->loadSum(['timeEntries as unbilled_cents' => fn ($q) => $q->unbilled()], 'amount_cents')
            ->loadSum(['expenses as unbilled_expenses_cents' => fn ($q) => $q->unbilled()], 'amount_cents');

        return new MatterResource($matter);
    }

    public function update(Request $request, Matter $matter): MatterResource
    {
        Gate::authorize('work-matters');

        $matter->update($request->validate(array_map(fn (array $rules) => ['sometimes', ...$rules], $this->rules())));
        if ($matter->wasChanged('responsible_lawyer_id')) {
            $this->tellResponsibleLawyer($matter, $request->user());
        }

        return new MatterResource($matter->load(['client', 'responsibleLawyer', 'parties']));
    }

    public function destroy(Matter $matter): JsonResponse
    {
        Gate::authorize('manage-firm');

        if ($matter->invoices()->exists() || $matter->trustAccounts()->exists()) {
            abort(422, 'Matters with invoices or trust accounts cannot be deleted. Close the matter instead.');
        }

        $matter->delete();

        return response()->json(null, 204);
    }

    public function transition(Request $request, Matter $matter, TransitionMatterStatus $transition): MatterResource
    {
        Gate::authorize('practice-law');

        $validated = $request->validate([
            'status' => ['required', new Enum(MatterStatus::class)],
            'reason' => ['nullable', 'string', 'max:2000'],
            'ask_feedback' => ['boolean'],
        ]);

        $transition->execute($matter, MatterStatus::from($validated['status']), $request->user(), $validated['reason'] ?? null, $validated['ask_feedback'] ?? true);

        return new MatterResource($matter->load(['client', 'responsibleLawyer', 'parties']));
    }

    public function timeline(Matter $matter): AnonymousResourceCollection
    {
        return MatterStatusEventResource::collection($matter->statusEvents()->with('changedBy')->get());
    }

    private function tellResponsibleLawyer(Matter $matter, User $by): void
    {
        if ($matter->responsible_lawyer_id && $matter->responsible_lawyer_id !== $by->id) {
            User::find($matter->responsible_lawyer_id)?->notify(new WorkAssigned($matter, $by));
        }
    }

    private function rules(): array
    {
        return [
            'client_id' => ['required', 'integer', Rule::exists('clients', 'id')->where('firm_id', request()->user()->firm_id)->whereNull('deleted_at')],
            'responsible_lawyer_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('firm_id', request()->user()->firm_id)],
            'title' => ['required', 'string', 'max:255'],
            'case_type' => ['required', 'string', 'max:64'],
            'case_number' => ['nullable', 'string', 'max:64'],
            'court' => ['nullable', 'string', 'max:255'],
            'court_branch' => ['nullable', 'string', 'max:255'],
            'judge' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'opened_at' => ['nullable', 'date', 'before_or_equal:today'],
            'fee_arrangement' => ['sometimes', new Enum(FeeArrangement::class)],
            'fixed_fee_cents' => ['nullable', 'integer', 'min:0', 'max:2000000000'],
            'acceptance_fee_cents' => ['nullable', 'integer', 'min:0', 'max:2000000000'],
            'appearance_fee_cents' => ['nullable', 'integer', 'min:0', 'max:2000000000'],
            'contingency_basis_points' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'retainer_auto_bill' => ['boolean'],
            'retainer_billing_day' => ['integer', 'min:1', 'max:28'],
            'retainer_auto_issue' => ['boolean'],
            'client_role' => [Rule::in(PleadingAssembler::CLIENT_ROLES)],
            'nature_of_action' => ['nullable', 'string', 'max:255'],
        ];
    }
}
