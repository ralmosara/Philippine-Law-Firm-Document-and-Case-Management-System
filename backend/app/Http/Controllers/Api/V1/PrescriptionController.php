<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Matters\Models\Matter;
use App\Domain\Prescription\MatterPrescription;
use App\Domain\Prescription\PrescriptionPeriods;
use App\Domain\Prescription\Prescriptions;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** Prescription of causes of action: per matter, and firm-wide by how soon they prescribe. */
class PrescriptionController extends Controller
{
    public function __construct(private readonly Prescriptions $prescriptions) {}

    /** The built-in periods, grouped, for choosing one. */
    public function periods(): JsonResponse
    {
        return response()->json([
            'groups' => collect(PrescriptionPeriods::GROUPS)->map(fn ($label, $group) => [
                'group' => $group,
                'label' => $label,
                'periods' => collect(PrescriptionPeriods::PERIODS)->filter(fn ($p) => $p['group'] === $group)->map(fn ($p, $key) => ['key' => $key, ...$p])->values(),
            ])->values(),
            'interruptions' => collect(MatterPrescription::INTERRUPTIONS)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values(),
        ]);
    }

    /** Firm-wide: still running on open matters, soonest first (prescribed ones first of all). */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('work-matters');
        $validated = $request->validate(['within' => ['nullable', 'integer', 'min:1', 'max:3650']]);

        $rows = MatterPrescription::query()
            ->where('status', MatterPrescription::RUNNING)
            ->whereDate('last_day', '<=', today()->addDays((int) ($validated['within'] ?? 365)))
            ->whereHas('matter', fn ($q) => $q->where('status', '!=', 'closed'))
            ->with('matter:id,reference,title,responsible_lawyer_id', 'matter.responsibleLawyer:id,name')
            ->orderBy('last_day')
            ->limit(500)
            ->get();

        return response()->json(['data' => $rows->map(fn (MatterPrescription $p) => $this->payload($p) + [
            'matter' => ['id' => $p->matter->id, 'reference' => $p->matter->reference, 'title' => $p->matter->title, 'lawyer' => $p->matter->responsibleLawyer?->name],
        ])]);
    }

    public function forMatter(Matter $matter): JsonResponse
    {
        Gate::authorize('work-matters');

        return response()->json([
            'data' => MatterPrescription::query()->where('matter_id', $matter->id)->orderByDesc('status')->orderBy('last_day')->get()->map(fn ($p) => $this->payload($p)),
        ]);
    }

    public function store(Request $request, Matter $matter): JsonResponse
    {
        Gate::authorize('work-matters');

        return response()->json($this->payload($this->prescriptions->save($matter, $this->validated($request), $request->user())), 201);
    }

    public function update(Request $request, MatterPrescription $prescription): JsonResponse
    {
        Gate::authorize('work-matters');
        $prescription->loadMissing('matter');

        return response()->json($this->payload($this->prescriptions->save($prescription->matter, $this->validated($request), $request->user(), $prescription)));
    }

    public function destroy(MatterPrescription $prescription): JsonResponse
    {
        Gate::authorize('work-matters');
        $prescription->delete();

        return response()->json(null, 204);
    }

    public function interrupt(Request $request, MatterPrescription $prescription): JsonResponse
    {
        Gate::authorize('work-matters');
        $validated = $request->validate([
            'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'kind' => ['required', Rule::in(array_keys(MatterPrescription::INTERRUPTIONS))],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        return response()->json($this->payload($this->prescriptions->interrupt($prescription, $validated['date'], $validated['kind'], $validated['note'] ?? null)));
    }

    /** The action was filed (prescription stops), or undo that. */
    public function filed(Request $request, MatterPrescription $prescription): JsonResponse
    {
        Gate::authorize('work-matters');
        $validated = $request->validate(['filed_on' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'], 'reopen' => ['boolean']]);

        $result = ($validated['reopen'] ?? false)
            ? $this->prescriptions->reopen($prescription)
            : $this->prescriptions->markFiled($prescription, $validated['filed_on'] ?? today()->toDateString());

        return response()->json($this->payload($result));
    }

    /** What a period would come to, before saving. */
    public function preview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'years' => ['required', 'integer', 'min:0', 'max:100'],
            'months' => ['required', 'integer', 'min:0', 'max:1200'],
        ]);
        $result = $this->prescriptions->compute(CarbonImmutable::parse($validated['from']), (int) $validated['years'], (int) $validated['months']);

        return response()->json(['last_day' => $result['last_day']->toDateString(), 'file_by' => $result['file_by']->toDateString(), 'adjustments' => $result['adjustments']]);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'period_key' => ['nullable', Rule::in(array_keys(PrescriptionPeriods::PERIODS))],
            'label' => ['required_without:period_key', 'nullable', 'string', 'max:255'],
            'years' => ['required_without:period_key', 'nullable', 'integer', 'min:0', 'max:100'],
            'months' => ['nullable', 'integer', 'min:0', 'max:1200'],
            'basis' => ['nullable', 'string', 'max:500'],
            'accrued_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], ['accrued_on.before_or_equal' => 'The cause of action cannot arise in the future.']);
    }

    private function payload(MatterPrescription $p): array
    {
        $days = $p->daysLeft();

        return [
            'id' => $p->id,
            'matter_id' => $p->matter_id,
            'period_key' => $p->period_key,
            'label' => $p->label,
            'years' => $p->years,
            'months' => $p->months,
            'basis' => $p->basis,
            'runs_from_hint' => $p->period_key ? (PrescriptionPeriods::find($p->period_key)['runs_from'] ?? null) : null,
            'interruptible' => $p->interruptible,
            'accrued_on' => $p->accrued_on->toDateString(),
            'runs_from' => $p->runs_from->toDateString(),
            'last_day' => $p->last_day->toDateString(),
            'file_by' => $p->file_by->toDateString(),
            'days_left' => $days,
            'state' => $p->status === MatterPrescription::FILED ? 'filed' : ($days < 0 ? 'prescribed' : ($days <= 30 ? 'urgent' : ($days <= 180 ? 'soon' : 'running'))),
            'interruptions' => collect($p->interruptions ?? [])->sortBy('date')->values()->map(fn ($i) => $i + ['kind_label' => MatterPrescription::INTERRUPTIONS[$i['kind']] ?? $i['kind']]),
            'filed_on' => $p->filed_on?->toDateString(),
            'notes' => $p->notes,
        ];
    }
}
