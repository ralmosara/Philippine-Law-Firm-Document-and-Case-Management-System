<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Documents\Requests\DocumentRequest;
use App\Domain\Documents\Requests\DocumentRequestItem;
use App\Domain\Documents\Requests\DocumentRequests;
use App\Domain\Matters\Models\Matter;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** Staff side of document requests: ask a client for documents and review what comes in. */
class DocumentRequestController extends Controller
{
    public function __construct(private readonly DocumentRequests $requests) {}

    public function index(Matter $matter): JsonResponse
    {
        Gate::authorize('work-matters');

        return response()->json(DocumentRequest::where('matter_id', $matter->id)->with(['items.file', 'creator:id,name'])->latest('id')->get()
            ->map(fn (DocumentRequest $r) => self::present($r, forClient: false)));
    }

    public function store(Request $request, Matter $matter): JsonResponse
    {
        Gate::authorize('work-matters');
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'message' => ['nullable', 'string', 'max:5000'],
            'due_on' => ['nullable', 'date', 'after_or_equal:today'],
            'items' => ['required', 'array', 'min:1', 'max:30'],
            'items.*.label' => ['required', 'string', 'max:255'],
            'items.*.description' => ['nullable', 'string', 'max:1000'],
            'items.*.required' => ['boolean'],
        ]);

        $documentRequest = $this->requests->create($matter, $request->user(), $validated['title'], $validated['message'] ?? null, $validated['due_on'] ?? null, $validated['items']);

        return response()->json(self::present($documentRequest->load(['items.file', 'creator:id,name']), forClient: false), 201);
    }

    public function review(Request $request, DocumentRequestItem $item): JsonResponse
    {
        Gate::authorize('work-matters');
        $validated = $request->validate([
            'decision' => ['required', Rule::in(['accept', 'reject'])],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $this->requests->review($item, $validated['decision'] === 'accept', $validated['note'] ?? null, $request->user());

        return response()->json(self::present(DocumentRequest::with(['items.file', 'creator:id,name'])->findOrFail($item->document_request_id), forClient: false));
    }

    public function cancel(DocumentRequest $documentRequest): JsonResponse
    {
        Gate::authorize('work-matters');

        return response()->json(self::present($this->requests->cancel($documentRequest)->load(['items.file', 'creator:id,name']), forClient: false));
    }

    /** The same shape for staff and the portal; review details only for staff. */
    public static function present(DocumentRequest $r, bool $forClient): array
    {
        $required = $r->items->where('required', true);

        return [
            'id' => $r->id,
            'matter_id' => $r->matter_id,
            'title' => $r->title,
            'message' => $r->message,
            'due_on' => $r->due_on?->toDateString(),
            'status' => $r->status,
            'progress' => ['done' => $required->where('status', DocumentRequestItem::ACCEPTED)->count(), 'total' => $required->count()],
            'created_by' => $forClient ? null : $r->creator?->name,
            'created_at' => $r->created_at?->toIso8601String(),
            'items' => $r->items->map(fn (DocumentRequestItem $i) => [
                'id' => $i->id,
                'label' => $i->label,
                'description' => $i->description,
                'required' => $i->required,
                'status' => $i->status,
                'review_note' => $i->review_note,
                'uploaded_at' => $i->uploaded_at?->toIso8601String(),
                'file' => $i->file ? ['id' => $i->file->id, 'name' => $i->file->original_name] : null,
            ])->values(),
        ];
    }
}
