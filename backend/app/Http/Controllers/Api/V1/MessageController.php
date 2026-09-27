<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Documents\Actions\StoreMatterFile;
use App\Domain\Matters\Models\Matter;
use App\Domain\Messaging\Models\MessageThread;
use App\Domain\Messaging\Services\Messaging;
use App\Http\Controllers\Controller;
use App\Http\Resources\MessageThreadResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** The firm's side of secure client messaging. */
class MessageController extends Controller
{
    public function __construct(private readonly Messaging $messaging) {}

    public function index(Request $request): JsonResponse
    {
        $threads = MessageThread::query()
            ->withUnreadCount('staff')
            ->with(['matter:id,reference,title', 'client:id,name', 'latestMessage'])
            ->when($request->query('matter_id'), fn ($q, $id) => $q->where('matter_id', $id))
            ->orderByDesc('last_message_at')
            ->paginate($this->perPage($request, 25));

        return response()->json([
            'data' => MessageThreadResource::for('staff', $threads->items()),
            'meta' => ['current_page' => $threads->currentPage(), 'last_page' => $threads->lastPage(), 'per_page' => $threads->perPage(), 'total' => $threads->total(), 'from' => $threads->firstItem(), 'to' => $threads->lastItem()],
            'links' => ['next' => $threads->nextPageUrl(), 'prev' => $threads->previousPageUrl()],
        ]);
    }

    /** Unread messages from clients across the firm, for the navigation badge. */
    public function unreadCount(): JsonResponse
    {
        $count = (int) MessageThread::query()->withUnreadCount('staff')->get()->sum('unread_count');

        return response()->json(['count' => $count]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('work-matters');

        $validated = $request->validate([
            'matter_id' => ['required', 'integer'],
            'subject' => ['required', 'string', 'max:255'],
            ...$this->messageRules(),
        ]);

        $matter = Matter::with('client')->findOrFail($validated['matter_id']);

        if (! $matter->client?->portal_enabled) {
            abort(422, 'The client needs portal access before you can message them securely.');
        }

        $thread = $this->messaging->start($matter, $request->user(), $validated['subject'], $validated['body'], $validated['file'] ?? null);

        return response()->json($this->payload($thread), 201);
    }

    public function show(MessageThread $thread): JsonResponse
    {
        $this->messaging->markRead($thread, 'staff');

        return response()->json($this->payload($thread));
    }

    public function reply(Request $request, MessageThread $thread): JsonResponse
    {
        Gate::authorize('work-matters');

        $validated = $request->validate($this->messageRules());
        $this->messaging->post($thread, $request->user(), $validated['body'], $validated['file'] ?? null);

        return response()->json($this->payload($thread), 201);
    }

    private function messageRules(): array
    {
        return [
            'body' => ['required', 'string', 'max:10000'],
            'file' => ['nullable', StoreMatterFile::rule()],
        ];
    }

    private function payload(MessageThread $thread): array
    {
        $thread->load(['matter:id,reference,title', 'client:id,name', 'messages.sender', 'messages.attachment']);

        return (new MessageThreadResource($thread, 'staff'))->resolve();
    }
}
