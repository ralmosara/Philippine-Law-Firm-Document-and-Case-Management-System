<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Billing\Models\TimeEntry;
use App\Domain\Matters\Models\Matter;
use App\Http\Controllers\Controller;
use App\Http\Resources\TimeEntryResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class TimeEntryController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $query = TimeEntry::query()
            ->with(['user', 'matter'])
            ->when($request->query('matter_id'), fn ($q, $id) => $q->where('matter_id', $id))
            ->when($request->query('user_id'), fn ($q, $id) => $q->where('user_id', $id))
            ->when($request->boolean('mine'), fn ($q) => $q->where('user_id', $request->user()->id))
            ->when($request->boolean('unbilled'), fn ($q) => $q->unbilled())
            ->when($request->query('from'), fn ($q, $from) => $q->whereDate('work_date', '>=', $from))
            ->when($request->query('to'), fn ($q, $to) => $q->whereDate('work_date', '<=', $to));

        $totals = (clone $query)->toBase()->selectRaw('COALESCE(SUM(minutes), 0) as minutes, COALESCE(SUM(amount_cents), 0) as amount_cents')->first();

        return TimeEntryResource::collection(
            $query->orderByDesc('work_date')->orderByDesc('id')->paginate($this->perPage($request, 25))
        )->additional(['totals' => ['minutes' => (int) $totals->minutes, 'amount_cents' => (int) $totals->amount_cents]]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('work-matters');

        $validated = $request->validate([
            ...$this->rules(),
            'matter_id' => ['required', 'integer'],
        ]);

        $matter = Matter::findOrFail($validated['matter_id']);
        $user = $request->user();

        $entry = TimeEntry::create([
            ...$validated,
            'firm_id' => $matter->firm_id,
            'user_id' => $user->id,
            // Only partners may bill at a rate other than the lawyer's standard rate.
            'rate_cents' => $user->role->canManageFinances() && isset($validated['rate_cents'])
                ? $validated['rate_cents']
                : $user->hourly_rate_cents,
        ]);

        return (new TimeEntryResource($entry->load(['user', 'matter'])))->response()->setStatusCode(201);
    }

    public function update(Request $request, TimeEntry $timeEntry): TimeEntryResource
    {
        Gate::authorize('modify-time-entry', $timeEntry);

        $validated = $request->validate(array_map(fn (array $r) => ['sometimes', ...$r], $this->rules()));

        if (! $request->user()->role->canManageFinances()) {
            unset($validated['rate_cents']);
        }

        $timeEntry->update($validated);

        return new TimeEntryResource($timeEntry->load(['user', 'matter']));
    }

    public function destroy(TimeEntry $timeEntry): JsonResponse
    {
        Gate::authorize('modify-time-entry', $timeEntry);

        $timeEntry->delete();

        return response()->json(null, 204);
    }

    private function rules(): array
    {
        return [
            'work_date' => ['required', 'date', 'before_or_equal:today'],
            'minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'description' => ['required', 'string', 'max:2000'],
            'is_billable' => ['boolean'],
            'rate_cents' => ['nullable', 'integer', 'min:0', 'max:10000000'],
        ];
    }
}
