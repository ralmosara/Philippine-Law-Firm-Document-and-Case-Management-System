<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Deadlines\Models\DeadlineRule;
use App\Http\Controllers\Controller;
use App\Http\Resources\DeadlineRuleResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Reglementary rules: system-wide Rules of Court periods (read-only) plus
 * the firm's own.
 */
class DeadlineRuleController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return DeadlineRuleResource::collection(
            DeadlineRule::availableTo($request->user()->firm_id)
                ->when($request->boolean('active_only'), fn ($q) => $q->where('is_active', true))
                ->orderByRaw('firm_id IS NOT NULL')
                ->orderBy('name')
                ->get()
        );
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('manage-firm');

        $rule = DeadlineRule::create([
            ...$request->validate($this->rules()),
            'firm_id' => $request->user()->firm_id,
        ]);

        return (new DeadlineRuleResource($rule))->response()->setStatusCode(201);
    }

    public function update(Request $request, int $rule): DeadlineRuleResource
    {
        Gate::authorize('manage-firm');

        $model = $this->firmRule($request, $rule);
        $model->update($request->validate(array_map(fn (array $rules) => ['sometimes', ...$rules], $this->rules())));

        return new DeadlineRuleResource($model);
    }

    public function destroy(Request $request, int $rule): JsonResponse
    {
        Gate::authorize('manage-firm');

        $this->firmRule($request, $rule)->delete();

        return response()->json(null, 204);
    }

    /** Only the firm's own rules are editable; system rules 404 here. */
    private function firmRule(Request $request, int $id): DeadlineRule
    {
        return DeadlineRule::where('firm_id', $request->user()->firm_id)->findOrFail($id);
    }

    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'trigger_event' => ['required', 'string', 'max:255'],
            'period_days' => ['required', 'integer', 'min:1', 'max:3650'],
            'period_type' => ['required', Rule::in(['calendar', 'working_days'])],
            'legal_basis' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['boolean'],
        ];
    }
}
