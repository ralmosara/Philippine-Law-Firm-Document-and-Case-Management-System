<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Budgets\MatterBudget;
use App\Domain\Budgets\MatterBudgets;
use App\Domain\Matters\Enums\MatterStatus;
use App\Domain\Matters\Models\Matter;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** A matter's budget and how much of it has been used. */
class MatterBudgetController extends Controller
{
    public function __construct(private readonly MatterBudgets $budgets) {}

    public function show(Matter $matter): JsonResponse
    {
        Gate::authorize('work-matters');

        return response()->json($this->payload($matter));
    }

    public function update(Request $request, Matter $matter): JsonResponse
    {
        Gate::authorize('set-budgets');

        $stages = collect(MatterStatus::cases())->reject(fn ($s) => $s === MatterStatus::Closed)->map->value->all();
        $validated = $request->validate([
            'basis' => ['required', Rule::in([MatterBudget::AMOUNT, MatterBudget::HOURS])],
            'total' => ['required', 'integer', 'min:1', 'max:100000000000'],
            'include_expenses' => ['boolean'],
            'stages' => ['nullable', 'array', 'max:'.count($stages)],
            'stages.*.stage' => ['required', 'distinct', Rule::in($stages)],
            'stages.*.total' => ['required', 'integer', 'min:1'],
            'shared_with_client' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], ['total.min' => 'Enter the budget.']);

        if (collect($validated['stages'] ?? [])->sum('total') > $validated['total']) {
            throw ValidationException::withMessages(['stages' => 'The stages add up to more than the whole budget.']);
        }

        $budget = MatterBudget::firstOrNew(['matter_id' => $matter->id]);
        $budget->fill([
            ...$validated,
            'stages' => array_values($validated['stages'] ?? []) ?: null,
            'include_expenses' => $validated['basis'] === MatterBudget::AMOUNT && ($validated['include_expenses'] ?? true),
            'firm_id' => $matter->firm_id,
            'updated_by' => $request->user()->id,
        ]);
        $budget->created_by ??= $request->user()->id;
        $budget->save();

        // A new total may cross (or un-cross) a threshold right away.
        $this->budgets->check($matter->id);

        return response()->json($this->payload($matter));
    }

    public function destroy(Matter $matter): JsonResponse
    {
        Gate::authorize('set-budgets');
        MatterBudget::query()->where('matter_id', $matter->id)->first()?->delete();

        return response()->json($this->payload($matter));
    }

    private function payload(Matter $matter): array
    {
        $budget = MatterBudget::query()->with('updater:id,name')->where('matter_id', $matter->id)->first();
        if ($budget === null) {
            return ['budget' => null, 'usage' => null];
        }
        $budget->setRelation('matter', $matter);

        return [
            'budget' => [
                'basis' => $budget->basis,
                'total' => $budget->total,
                'include_expenses' => $budget->include_expenses,
                'stages' => $budget->stages ?? [],
                'shared_with_client' => $budget->shared_with_client,
                'notes' => $budget->notes,
                'alerted_80_at' => $budget->alerted_80_at?->toIso8601String(),
                'alerted_100_at' => $budget->alerted_100_at?->toIso8601String(),
                'updated_by' => $budget->updater?->name,
                'updated_at' => $budget->updated_at?->toIso8601String(),
            ],
            'usage' => $this->budgets->usage($budget),
        ];
    }
}
