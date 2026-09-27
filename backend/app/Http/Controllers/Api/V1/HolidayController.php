<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Deadlines\Models\HolidayCalendar;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * The nationwide holiday calendar that shifts reglementary periods.
 * Changes affect every firm's future computations, so each one is audited.
 */
class HolidayController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $year = $request->integer('year', now()->year);

        $holidays = HolidayCalendar::whereYear('date', $year)->orderBy('date')->get()
            ->map(fn (HolidayCalendar $h) => ['id' => $h->id, 'date' => $h->date->toDateString(), 'name' => $h->name, 'type' => $h->type]);

        return response()->json(['year' => $year, 'data' => $holidays]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('manage-firm');

        $holiday = HolidayCalendar::create($request->validate([
            'date' => ['required', 'date', Rule::unique('holiday_calendar', 'date')],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(['regular', 'special_non_working', 'court_closure'])],
        ]));

        AuditLog::record('holiday_added', $request->user()->firm_id, $request->user(), null, $holiday->only(['date', 'name', 'type']));

        return response()->json(['id' => $holiday->id, 'date' => $holiday->date->toDateString(), 'name' => $holiday->name, 'type' => $holiday->type], 201);
    }

    public function destroy(Request $request, HolidayCalendar $holiday): JsonResponse
    {
        Gate::authorize('manage-firm');

        AuditLog::record('holiday_removed', $request->user()->firm_id, $request->user(), null, [
            'date' => $holiday->date->toDateString(),
            'name' => $holiday->name,
        ]);
        $holiday->delete();

        return response()->json(null, 204);
    }
}
