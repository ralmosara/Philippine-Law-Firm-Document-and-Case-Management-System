<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Assistant\Assistant;
use App\Domain\Assistant\Models\AiConversation;
use App\Domain\Assistant\Models\AiMessage;
use App\Domain\Documents\Actions\CreateDocumentVersion;
use App\Domain\Documents\Models\Document;
use App\Domain\Matters\Models\Matter;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** The matter assistant. Conversations are private to the lawyer who started them. */
class AssistantController extends Controller
{
    public function __construct(private readonly Assistant $assistant) {}

    public function status(Request $request): JsonResponse
    {
        $reason = $this->assistant->unavailableReason((int) $request->user()->firm_id);

        return response()->json(['available' => $reason === null, 'reason' => $reason]);
    }

    public function index(Request $request, Matter $matter): JsonResponse
    {
        $this->authorizeUse($request);

        $conversations = AiConversation::where('matter_id', $matter->id)->where('user_id', $request->user()->id)->latest('updated_at')->limit(50)->get();

        return response()->json(['data' => $conversations->map(fn (AiConversation $c) => ['id' => $c->id, 'title' => $c->title, 'updated_at' => $c->updated_at?->toIso8601String()])]);
    }

    public function ask(Request $request, Matter $matter): JsonResponse
    {
        $this->authorizeUse($request);

        $validated = $request->validate([
            'question' => ['required', 'string', 'max:8000'],
            'conversation_id' => ['nullable', 'integer'],
        ]);

        $conversation = isset($validated['conversation_id'])
            ? $this->own($request, $validated['conversation_id'], $matter->id)
            : null;

        $conversation = $this->assistant->ask($matter, $request->user(), $validated['question'], $conversation);

        return response()->json($this->payload($conversation), 201);
    }

    public function show(Request $request, int $conversation): JsonResponse
    {
        $this->authorizeUse($request, checkAvailability: false);

        return response()->json($this->payload($this->own($request, $conversation)));
    }

    public function retry(Request $request, int $message): JsonResponse
    {
        $this->authorizeUse($request);

        $answer = AiMessage::where('role', 'assistant')->where('status', AiMessage::FAILED)->findOrFail($message);
        $conversation = $this->own($request, $answer->conversation_id);
        $this->assistant->retry($answer);

        return response()->json($this->payload($conversation));
    }

    /** Turn an answer (usually a draft) into a new draft document on the matter. */
    public function saveAsDocument(Request $request, int $message, CreateDocumentVersion $versions): JsonResponse
    {
        Gate::authorize('work-matters');

        $validated = $request->validate(['title' => ['required', 'string', 'max:255']]);
        $answer = AiMessage::where('role', 'assistant')->where('status', AiMessage::COMPLETE)->findOrFail($message);
        $conversation = $this->own($request, $answer->conversation_id);

        $document = DB::transaction(function () use ($conversation, $validated, $answer, $request, $versions) {
            $document = Document::create([
                'firm_id' => $conversation->firm_id,
                'matter_id' => $conversation->matter_id,
                'title' => $validated['title'],
                'created_by' => $request->user()->id,
            ]);
            $versions->execute($document, (string) $answer->content, $request->user(), 'Drafted with the AI assistant; review before use');

            return $document;
        });

        return response()->json(['document_id' => $document->id], 201);
    }

    private function authorizeUse(Request $request, bool $checkAvailability = true): void
    {
        Gate::authorize('work-matters');

        if ($checkAvailability && ($reason = $this->assistant->unavailableReason((int) $request->user()->firm_id))) {
            abort(403, $reason);
        }
    }

    private function own(Request $request, int $id, ?int $matterId = null): AiConversation
    {
        return AiConversation::where('user_id', $request->user()->id)
            ->when($matterId, fn ($q) => $q->where('matter_id', $matterId))
            ->findOrFail($id);
    }

    private function payload(AiConversation $conversation): array
    {
        $conversation->load('messages');

        return [
            'id' => $conversation->id,
            'matter_id' => $conversation->matter_id,
            'title' => $conversation->title,
            'messages' => $conversation->messages->map(fn (AiMessage $m) => [
                'id' => $m->id,
                'role' => $m->role,
                'content' => $m->content,
                'status' => $m->status,
                'error' => $m->error,
                'created_at' => $m->created_at?->toIso8601String(),
            ]),
        ];
    }
}
