<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Billing\FilingFees;
use App\Domain\Matters\Models\Firm;
use App\Domain\Matters\Models\Matter;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** The firm's filing fee table, and estimates from it for a matter. */
class FilingFeeController extends Controller
{
    public function __construct(private readonly FilingFees $fees) {}

    public function show(Request $request): JsonResponse
    {
        Gate::authorize('work-matters');

        return response()->json($this->payload(Firm::findOrFail($request->user()->firm_id)));
    }

    /** Save and confirm the table: the managing partner vouches it matches the current rules. */
    public function update(Request $request): JsonResponse
    {
        Gate::authorize('manage-firm');
        $validated = $request->validate([
            'court' => ['required', 'string', 'max:120'],
            'brackets' => ['required', 'array', 'min:1', 'max:40'],
            'brackets.*.under_cents' => ['required', 'integer', 'min:1'],
            'brackets.*.fee_cents' => ['required', 'integer', 'min:0'],
            'excess' => ['nullable', 'array'],
            'excess.from_cents' => ['required_with:excess', 'integer', 'min:0'],
            'excess.base_cents' => ['required_with:excess', 'integer', 'min:0'],
            'excess.per_thousand_cents' => ['required_with:excess', 'integer', 'min:0'],
            'extras' => ['array', 'max:20'],
            'extras.*.label' => ['required', 'string', 'max:120'],
            'extras.*.fixed_cents' => ['nullable', 'integer', 'min:0'],
            'extras.*.percent_bps' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'extras.*.minimum_cents' => ['nullable', 'integer', 'min:0'],
            'confirm' => ['accepted'],
        ], ['confirm.accepted' => 'Confirm that you have checked the table against the current Rule 141 and circulars.']);

        $brackets = collect($validated['brackets'])->sortBy('under_cents')->values()->all();
        $schedule = ['court' => $validated['court'], 'brackets' => $brackets, 'excess' => $validated['excess'] ?? null, 'extras' => array_values($validated['extras'] ?? [])];

        return response()->json($this->payload($this->fees->confirm(Firm::findOrFail($request->user()->firm_id), $schedule, $request->user())));
    }

    public function estimate(Request $request, Matter $matter): JsonResponse
    {
        Gate::authorize('work-matters');
        $validated = $request->validate(['claim_cents' => ['required', 'integer', 'min:0', 'max:100000000000000']]);

        return response()->json($this->fees->estimate(Firm::findOrFail($matter->firm_id), $validated['claim_cents']));
    }

    private function payload(Firm $firm): array
    {
        return [
            'schedule' => $this->fees->schedule($firm),
            'confirmed' => $this->fees->isConfirmed($firm),
            'confirmed_at' => $firm->filing_fee_schedule_confirmed_at?->toIso8601String(),
            'confirmed_by' => User::find($firm->filing_fee_schedule_confirmed_by)?->name,
        ];
    }
}
