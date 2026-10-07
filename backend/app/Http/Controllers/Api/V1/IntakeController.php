<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Compliance\Models\ConflictCheck;
use App\Domain\Intake\Models\IntakeRequest;
use App\Domain\Intake\Services\IntakeService;
use App\Domain\Prescription\PrescriptionPeriods;
use App\Domain\Prescription\Prescriptions;
use App\Http\Controllers\Controller;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** The firm's review of online consultation requests. */
class IntakeController extends Controller
{
    public function __construct(private readonly IntakeService $intake) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('work-matters');

        $validated = $request->validate(['status' => ['nullable', Rule::in(['new', 'scheduled', 'accepted', 'declined', 'open'])]]);

        $requests = IntakeRequest::query()
            ->with('assignedLawyer:id,name')
            ->when($validated['status'] ?? 'open', fn ($q, $status) => $status === 'open'
                ? $q->whereIn('status', [IntakeRequest::NEW, IntakeRequest::SCHEDULED])
                : $q->where('status', $status))
            ->latest('id')
            ->paginate($this->perPage($request, 25));

        return response()->json([
            'data' => collect($requests->items())->map(fn (IntakeRequest $r) => $this->summary($r)),
            'meta' => ['current_page' => $requests->currentPage(), 'last_page' => $requests->lastPage(), 'per_page' => $requests->perPage(), 'total' => $requests->total(), 'from' => $requests->firstItem(), 'to' => $requests->lastItem()],
            'links' => ['next' => $requests->nextPageUrl(), 'prev' => $requests->previousPageUrl()],
            'open_count' => IntakeRequest::whereIn('status', [IntakeRequest::NEW, IntakeRequest::SCHEDULED])->count(),
        ]);
    }

    public function show(IntakeRequest $intakeRequest): JsonResponse
    {
        Gate::authorize('work-matters');

        return response()->json($this->detail($intakeRequest));
    }

    public function schedule(Request $request, IntakeRequest $intakeRequest): JsonResponse
    {
        Gate::authorize('practice-law');

        $validated = $request->validate([
            'consultation_at' => ['required', 'date', 'after:now'],
            'assigned_lawyer_id' => ['required', 'integer', Rule::exists('users', 'id')->where('firm_id', $intakeRequest->firm_id)->where('is_active', true)],
        ]);

        $this->intake->schedule($intakeRequest, CarbonImmutable::parse($validated['consultation_at'], 'Asia/Manila'), User::findOrFail($validated['assigned_lawyer_id']), $request->user());

        return response()->json($this->detail($intakeRequest->fresh()));
    }

    public function decline(Request $request, IntakeRequest $intakeRequest): JsonResponse
    {
        Gate::authorize('practice-law');

        $validated = $request->validate([
            'internal_notes' => ['nullable', 'string', 'max:2000'],
            'notify' => ['boolean'],
        ]);

        $this->intake->decline($intakeRequest, $validated['internal_notes'] ?? null, $validated['notify'] ?? true, $request->user());

        return response()->json($this->detail($intakeRequest->fresh()));
    }

    /**
     * The lawyer's screening for prescription: the type of claim, and (to
     * correct what the applicant wrote) when it arose. Tracked on the matter
     * once the case is taken.
     */
    public function prescription(Request $request, IntakeRequest $intakeRequest): JsonResponse
    {
        Gate::authorize('work-matters');
        $validated = $request->validate([
            'period_key' => ['nullable', Rule::in(array_keys(PrescriptionPeriods::PERIODS))],
            'incident_on' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
        ]);

        $intakeRequest->forceFill([
            'prescription_period_key' => $validated['period_key'] ?? null,
            ...(array_key_exists('incident_on', $validated) ? ['incident_on' => $validated['incident_on']] : []),
        ])->save();

        return response()->json($this->detail($intakeRequest->fresh()));
    }

    public function accept(Request $request, IntakeRequest $intakeRequest): JsonResponse
    {
        Gate::authorize('practice-law');

        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'responsible_lawyer_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('firm_id', $intakeRequest->firm_id)],
        ]);

        $matter = $this->intake->accept($intakeRequest, $request->user(), $validated['title'] ?? null, $validated['responsible_lawyer_id'] ?? null);

        return response()->json(['matter_id' => $matter->id, 'request' => $this->detail($intakeRequest->fresh())]);
    }

    private function summary(IntakeRequest $r): array
    {
        return [
            'id' => $r->id,
            'status' => $r->status,
            'name' => $r->name,
            'email' => $r->email,
            'case_type' => $r->case_type,
            'conflict_status' => $r->conflict_status,
            'consultation_at' => $r->consultation_at?->toIso8601String(),
            'assigned_lawyer' => $r->assignedLawyer ? ['id' => $r->assignedLawyer->id, 'name' => $r->assignedLawyer->name] : null,
            'created_at' => $r->created_at?->toIso8601String(),
            'incident_on' => $r->incident_on?->toDateString(),
            'answers' => $r->answers ?? [],
            'locale' => $r->locale,
            // Only while the case is still to be decided; once taken, the matter tracks it.
            'prescription' => $r->isOpen() ? app(Prescriptions::class)->screen($r->incident_on, $r->prescription_period_key) : null,
        ];
    }

    private function detail(IntakeRequest $r): array
    {
        $r->loadMissing('assignedLawyer:id,name');

        return [
            ...$this->summary($r),
            'phone' => $r->phone,
            'client_type' => $r->client_type,
            'description' => $r->description,
            'opposing_parties' => $r->opposing_parties,
            'preferred_times' => $r->preferred_times,
            'consent_at' => $r->consent_at?->toIso8601String(),
            'internal_notes' => $r->internal_notes,
            'client_id' => $r->client_id,
            'matter_id' => $r->matter_id,
            'conflict_checks' => $r->conflictChecks()->map(fn (ConflictCheck $c) => [
                'id' => $c->id,
                'search_term' => $c->search_term,
                'status' => $c->status->value,
                'match_count' => $c->match_count,
                'matches' => $c->matches,
            ])->values(),
        ];
    }
}
