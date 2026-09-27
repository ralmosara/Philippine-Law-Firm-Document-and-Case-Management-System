<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Deadlines\Enums\DeadlineKind;
use App\Domain\Deadlines\Models\MatterDeadline;
use App\Domain\Deadlines\Services\HearingOutcomes;
use App\Domain\Matters\Enums\PartyRole;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** The day's hearings, for the phone in the courtroom corridor. */
class CourtDayController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('work-matters');
        $validated = $request->validate(['date' => ['nullable', 'date'], 'mine' => ['nullable', 'boolean']]);
        $date = CarbonImmutable::parse($validated['date'] ?? 'today');

        $hearings = MatterDeadline::query()
            ->where('kind', DeadlineKind::Hearing->value)
            ->whereDate('due_date', $date->toDateString())
            ->when($request->boolean('mine'), fn ($q) => $q->where('assigned_to', $request->user()->id))
            ->with(['matter.client:id,name,phone', 'matter.parties', 'matter.responsibleLawyer:id,name', 'assignee:id,name'])
            ->get()
            ->sortBy(fn (MatterDeadline $d) => [$d->due_time ?? '99:99', $d->id])
            ->values();

        return response()->json([
            'date' => $date->toDateString(),
            'hearings' => $hearings->map(fn (MatterDeadline $d) => [
                'id' => $d->id,
                'title' => $d->title,
                'time' => $d->due_time ? substr($d->due_time, 0, 5) : null,
                'location' => $d->location,
                'notes' => $d->notes,
                'status' => $d->status->value,
                'assignee' => $d->assignee?->name,
                'matter' => [
                    'id' => $d->matter->id,
                    'reference' => $d->matter->reference,
                    'title' => $d->matter->title,
                    'case_number' => $d->matter->case_number,
                    'court' => trim(implode(', ', array_filter([$d->matter->court, $d->matter->court_branch]))) ?: null,
                    'judge' => $d->matter->judge,
                    'client' => $d->matter->client?->name,
                    'client_phone' => $d->matter->client?->phone,
                    'client_role' => $d->matter->client_role,
                    'opposing' => $d->matter->parties->where('role', PartyRole::AdverseParty)->pluck('name')->values(),
                    'opposing_counsel' => $d->matter->parties->map(fn ($p) => $p->role === PartyRole::AdverseCounsel ? $p->name : $p->counsel_name)->filter()->unique()->values(),
                ],
            ]),
        ]);
    }

    public function outcome(Request $request, MatterDeadline $deadline, HearingOutcomes $outcomes): JsonResponse
    {
        Gate::authorize('work-matters');
        $validated = $request->validate([
            'outcome' => ['required', Rule::in([HearingOutcomes::HELD, HearingOutcomes::RESET, HearingOutcomes::CANCELLED])],
            'notes' => ['nullable', 'string', 'max:5000'],
            'next_date' => ['nullable', 'date', 'after:today'],
            'next_time' => ['nullable', 'date_format:H:i'],
            'next_location' => ['nullable', 'string', 'max:255'],
            'next_title' => ['nullable', 'string', 'max:255'],
            'follow_ups' => ['array', 'max:10'],
            'follow_ups.*.title' => ['required', 'string', 'max:255'],
            'follow_ups.*.days' => ['required', 'integer', 'min:1', 'max:365'],
            'follow_ups.*.kind' => ['nullable', Rule::in(['filing', 'task'])],
            'minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
        ]);

        $result = $outcomes->record($deadline, $validated, $request->user());

        return response()->json([
            'status' => $result['hearing']->status->value,
            'next' => $result['next'] ? ['id' => $result['next']->id, 'date' => $result['next']->due_date->toDateString()] : null,
            'follow_ups' => array_map(fn ($d) => ['id' => $d->id, 'title' => $d->title, 'due_date' => $d->due_date->toDateString()], $result['follow_ups']),
            'time_entry_id' => $result['time_entry']?->id,
        ]);
    }
}
