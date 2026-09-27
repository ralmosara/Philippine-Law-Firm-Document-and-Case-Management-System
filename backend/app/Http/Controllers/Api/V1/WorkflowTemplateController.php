<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Deadlines\Enums\DeadlineKind;
use App\Domain\Matters\Models\CaseWorkflowTemplate;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rules\Enum;

class WorkflowTemplateController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => CaseWorkflowTemplate::orderBy('case_type')->orderBy('name')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('manage-firm');

        return response()->json(CaseWorkflowTemplate::create($request->validate($this->rules())), 201);
    }

    public function update(Request $request, CaseWorkflowTemplate $workflowTemplate): JsonResponse
    {
        Gate::authorize('manage-firm');

        $workflowTemplate->update($request->validate(array_map(fn (array $r) => ['sometimes', ...$r], $this->rules())));

        return response()->json($workflowTemplate);
    }

    public function destroy(CaseWorkflowTemplate $workflowTemplate): JsonResponse
    {
        Gate::authorize('manage-firm');

        $workflowTemplate->delete();

        return response()->json(null, 204);
    }

    private function rules(): array
    {
        return [
            'case_type' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:255'],
            'tasks' => ['required', 'array', 'min:1', 'max:50'],
            'tasks.*.title' => ['required', 'string', 'max:255'],
            'tasks.*.days_offset' => ['required', 'integer', 'min:0', 'max:3650'],
            'tasks.*.kind' => ['nullable', new Enum(DeadlineKind::class)],
            'is_active' => ['boolean'],
        ];
    }
}
