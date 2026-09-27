<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Compliance\Models\McleCompliancePeriod;
use App\Domain\Compliance\Models\McleCredit;
use App\Domain\Compliance\Services\MCLETracker;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class McleController extends Controller
{
    public function __construct(private readonly MCLETracker $tracker) {}

    public function periods(): JsonResponse
    {
        return response()->json([
            'data' => McleCompliancePeriod::orderByDesc('start_date')->get(),
            'current_id' => McleCompliancePeriod::current()?->id,
        ]);
    }

    public function storePeriod(Request $request): JsonResponse
    {
        Gate::authorize('manage-firm');

        $period = McleCompliancePeriod::create($request->validate([
            'name' => ['required', 'string', 'max:255'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after:start_date'],
            'required_units' => ['required', 'integer', 'min:1', 'max:200'],
        ]));

        return response()->json($period, 201);
    }

    /**
     * A lawyer's credits and status for a period (defaults: self, current period).
     */
    public function status(Request $request): JsonResponse
    {
        [$user, $period] = $this->resolveUserAndPeriod($request);

        if ($period === null) {
            return response()->json(['status' => null, 'credits' => []]);
        }

        return response()->json([
            'user' => ['id' => $user->id, 'name' => $user->name],
            'status' => $this->tracker->getComplianceStatus($user->id, $period->id),
            'credits' => McleCredit::where('user_id', $user->id)->where('period_id', $period->id)->orderByDesc('date_earned')->get(),
        ]);
    }

    public function firm(Request $request): JsonResponse
    {
        Gate::authorize('manage-firm');

        $period = $request->query('period_id')
            ? McleCompliancePeriod::findOrFail($request->query('period_id'))
            : McleCompliancePeriod::current();

        return response()->json([
            'period' => $period,
            'data' => $period ? $this->tracker->firmSummary($request->user()->firm_id, $period) : [],
        ]);
    }

    public function storeCredit(Request $request): JsonResponse
    {
        Gate::authorize('practice-law');

        $validated = $request->validate([
            'user_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('firm_id', $request->user()->firm_id)],
            'period_id' => ['required', 'integer', 'exists:mcle_compliance_periods,id'],
            'title' => ['required', 'string', 'max:255'],
            'provider' => ['nullable', 'string', 'max:255'],
            'subject_area' => ['nullable', 'string', 'max:64'],
            'units' => ['required', 'numeric', 'min:0.25', 'max:36'],
            'date_earned' => ['required', 'date', 'before_or_equal:today'],
            'certificate_number' => ['nullable', 'string', 'max:64'],
        ]);

        $userId = $validated['user_id'] ?? $request->user()->id;

        if ((int) $userId !== $request->user()->id) {
            Gate::authorize('manage-firm');
        }

        $credit = McleCredit::create([...$validated, 'user_id' => $userId]);

        return response()->json($credit, 201);
    }

    public function destroyCredit(McleCredit $credit): JsonResponse
    {
        Gate::authorize('modify-mcle-credit', $credit);

        $credit->delete();

        return response()->json(null, 204);
    }

    /**
     * @return array{0: User, 1: McleCompliancePeriod|null}
     */
    private function resolveUserAndPeriod(Request $request): array
    {
        $user = $request->user();

        if ($request->query('user_id') && (int) $request->query('user_id') !== $user->id) {
            Gate::authorize('manage-firm');
            $user = User::findOrFail($request->query('user_id'));
        }

        $period = $request->query('period_id')
            ? McleCompliancePeriod::findOrFail($request->query('period_id'))
            : McleCompliancePeriod::current();

        return [$user, $period];
    }
}
