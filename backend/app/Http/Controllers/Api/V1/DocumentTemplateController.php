<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Documents\Models\DocumentTemplate;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class DocumentTemplateController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $templates = DocumentTemplate::query()
            ->when($request->query('category'), fn ($q, $category) => $q->where('category', $category))
            ->when($request->query('search'), fn ($q, $search) => $q->whereLike('name', "%{$search}%"))
            ->orderBy('name')
            ->get(['id', 'name', 'category', 'body', 'updated_at']);

        return response()->json(['data' => $templates]);
    }

    public function show(DocumentTemplate $documentTemplate): JsonResponse
    {
        return response()->json($documentTemplate);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('work-matters');

        return response()->json(DocumentTemplate::create($request->validate($this->rules())), 201);
    }

    public function update(Request $request, DocumentTemplate $documentTemplate): JsonResponse
    {
        Gate::authorize('work-matters');

        $documentTemplate->update($request->validate(array_map(fn (array $r) => ['sometimes', ...$r], $this->rules())));

        return response()->json($documentTemplate);
    }

    public function destroy(DocumentTemplate $documentTemplate): JsonResponse
    {
        Gate::authorize('manage-firm');

        $documentTemplate->delete();

        return response()->json(null, 204);
    }

    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:64'],
            'body' => ['required', 'string', 'max:200000'],
        ];
    }
}
