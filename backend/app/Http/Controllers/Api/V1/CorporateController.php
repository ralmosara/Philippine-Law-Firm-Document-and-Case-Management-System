<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Corporate\CorporateSecretarial;
use App\Domain\Corporate\Models\CorporateObligation;
use App\Domain\Corporate\Models\CorporateProfile;
use App\Domain\Matters\Models\Client;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** Corporate secretarial work for client companies: profiles, yearly SEC/BIR obligations, templates. */
class CorporateController extends Controller
{
    private const MONTH_DAY = 'regex:/^(0[1-9]|1[0-2])-(0[1-9]|[12][0-9]|3[01])$/';

    public function __construct(private readonly CorporateSecretarial $secretarial) {}

    /** Every client company with its obligations for the year. */
    public function index(Request $request): JsonResponse
    {
        $year = (int) ($request->validate(['year' => ['nullable', 'integer', 'min:2020', 'max:2100']])['year'] ?? now()->year);

        $profiles = CorporateProfile::with(['client:id,name,tin', 'responsibleLawyer:id,name'])->get();
        foreach ($profiles as $profile) {
            $this->secretarial->ensureYear($profile, $year);
        }

        $obligations = CorporateObligation::where('year', $year)->with('completer:id,name')
            ->orderBy('due_on')->get()->groupBy('client_id');

        return response()->json([
            'year' => $year,
            'kinds' => CorporateObligation::KINDS,
            'companies' => $profiles->sortBy(fn ($p) => $p->client?->name)->values()->map(fn (CorporateProfile $p) => [
                'profile' => $this->presentProfile($p),
                'obligations' => ($obligations[$p->client_id] ?? collect())->map(fn ($o) => $this->presentObligation($o))->values(),
            ]),
            'upcoming' => CorporateObligation::where('status', CorporateObligation::PENDING)
                ->whereDate('due_on', '<=', today()->addDays(30)->toDateString())
                ->with('client:id,name')->orderBy('due_on')->limit(50)->get()
                ->map(fn ($o) => [...$this->presentObligation($o), 'client_name' => $o->client?->name]),
        ]);
    }

    /** One client's corporate profile and obligations (current and next year), for the client page. */
    public function show(Client $client): JsonResponse
    {
        $profile = CorporateProfile::where('client_id', $client->id)->with('responsibleLawyer:id,name')->first();
        if ($profile) {
            $this->secretarial->ensureYear($profile, now()->year);
            $this->secretarial->ensureYear($profile, now()->year + 1);
        }

        return response()->json([
            'profile' => $profile ? $this->presentProfile($profile) : null,
            'obligations' => $profile
                ? CorporateObligation::where('client_id', $client->id)->where('year', '>=', now()->year - 1)
                    ->with('completer:id,name')->orderBy('due_on')->get()->map(fn ($o) => $this->presentObligation($o))
                : [],
        ]);
    }

    public function saveProfile(Request $request, Client $client): JsonResponse
    {
        Gate::authorize('work-matters');
        $validated = $request->validate([
            'sec_registration_no' => ['nullable', 'string', 'max:50'],
            'incorporated_on' => ['nullable', 'date', 'before_or_equal:today'],
            'fiscal_year_end' => ['required', 'string', self::MONTH_DAY],
            'annual_meeting_date' => ['nullable', 'string', self::MONTH_DAY],
            'principal_office' => ['nullable', 'string', 'max:500'],
            'corporate_secretary' => ['nullable', 'string', 'max:255'],
            'responsible_lawyer_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('firm_id', $request->user()->firm_id)],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $profile = CorporateProfile::firstOrNew(['client_id' => $client->id], ['firm_id' => $client->firm_id]);
        $scheduleChanged = $profile->exists && ($profile->fiscal_year_end !== $validated['fiscal_year_end']
            || $profile->annual_meeting_date !== ($validated['annual_meeting_date'] ?? null));
        $profile->fill($validated)->save();

        // New dates regenerate the pending generated obligations; done ones stay as recorded.
        if ($scheduleChanged) {
            CorporateObligation::where('client_id', $client->id)->where('status', CorporateObligation::PENDING)
                ->where('kind', '!=', 'custom')->where('year', '>=', now()->year)->delete();
        }
        $this->secretarial->ensureYear($profile, now()->year);
        $this->secretarial->ensureYear($profile, now()->year + 1);

        return response()->json($this->presentProfile($profile->load(['client:id,name,tin', 'responsibleLawyer:id,name'])), $profile->wasRecentlyCreated ? 201 : 200);
    }

    public function storeObligation(Request $request, Client $client): JsonResponse
    {
        Gate::authorize('work-matters');
        abort_unless(CorporateProfile::where('client_id', $client->id)->exists(), 422, 'Add the corporate profile first.');
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'due_on' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $obligation = CorporateObligation::create([
            ...$validated,
            'firm_id' => $client->firm_id,
            'client_id' => $client->id,
            'kind' => 'custom',
            'year' => (int) substr($validated['due_on'], 0, 4),
        ]);

        return response()->json($this->presentObligation($obligation), 201);
    }

    public function updateObligation(Request $request, CorporateObligation $corporateObligation): JsonResponse
    {
        Gate::authorize('work-matters');
        $validated = $request->validate([
            'status' => ['required', Rule::in([CorporateObligation::PENDING, CorporateObligation::DONE, CorporateObligation::NOT_APPLICABLE])],
            'done_on' => ['required_if:status,done', 'nullable', 'date', 'before_or_equal:today'],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'due_on' => ['nullable', 'date'],
        ]);

        $done = $validated['status'] === CorporateObligation::DONE;
        $corporateObligation->forceFill([
            'status' => $validated['status'],
            'done_on' => $done ? $validated['done_on'] : null,
            'completed_by' => $done ? $request->user()->id : null,
            'reference' => $validated['reference'] ?? $corporateObligation->reference,
            'notes' => $validated['notes'] ?? $corporateObligation->notes,
            'due_on' => $validated['due_on'] ?? $corporateObligation->due_on,
            // A moved date gets its reminders again.
            'last_reminder' => isset($validated['due_on']) && $validated['due_on'] !== $corporateObligation->due_on->toDateString()
                ? null : $corporateObligation->last_reminder,
        ])->save();

        return response()->json($this->presentObligation($corporateObligation->load('completer:id,name')));
    }

    public function destroyObligation(CorporateObligation $corporateObligation): JsonResponse
    {
        Gate::authorize('work-matters');
        abort_unless($corporateObligation->kind === 'custom', 422, 'Standard obligations can be marked not applicable instead.');
        $corporateObligation->delete();

        return response()->json(null, 204);
    }

    public function installTemplates(Request $request): JsonResponse
    {
        Gate::authorize('work-matters');

        return response()->json(['added' => $this->secretarial->installTemplates($request->user()->firm_id)]);
    }

    private function presentProfile(CorporateProfile $p): array
    {
        return [
            'id' => $p->id,
            'client_id' => $p->client_id,
            'client_name' => $p->client?->name,
            'client_tin' => $p->client?->tin,
            'sec_registration_no' => $p->sec_registration_no,
            'incorporated_on' => $p->incorporated_on?->toDateString(),
            'fiscal_year_end' => $p->fiscal_year_end,
            'annual_meeting_date' => $p->annual_meeting_date,
            'principal_office' => $p->principal_office,
            'corporate_secretary' => $p->corporate_secretary,
            'responsible_lawyer_id' => $p->responsible_lawyer_id,
            'responsible_lawyer' => $p->responsibleLawyer?->name,
            'notes' => $p->notes,
        ];
    }

    private function presentObligation(CorporateObligation $o): array
    {
        return [
            'id' => $o->id,
            'client_id' => $o->client_id,
            'kind' => $o->kind,
            'title' => $o->title,
            'year' => $o->year,
            'due_on' => $o->due_on->toDateString(),
            'status' => $o->status,
            'is_overdue' => $o->status === CorporateObligation::PENDING && $o->due_on->lt(today()),
            'done_on' => $o->done_on?->toDateString(),
            'reference' => $o->reference,
            'notes' => $o->notes,
            'completed_by' => $o->completer?->name,
        ];
    }
}
