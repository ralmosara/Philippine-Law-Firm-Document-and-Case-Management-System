<?php

namespace App\Http\Controllers;

use App\Domain\Documents\Actions\StoreMatterFile;
use App\Domain\Matters\Models\Client;
use App\Domain\Messaging\Models\MessageThread;
use App\Domain\Messaging\Services\Messaging;
use App\Http\Resources\MessageThreadResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The client's side of secure messaging: only their own matters' threads. */
class PortalMessageController extends Controller
{
    public function __construct(private readonly Messaging $messaging) {}

    public function index(Request $request): JsonResponse
    {
        $threads = MessageThread::query()
            ->where('client_id', $this->client($request)->id)
            ->withUnreadCount('client')
            ->with(['matter:id,reference,title', 'latestMessage'])
            ->when($request->query('matter_id'), fn ($q, $id) => $q->where('matter_id', $id))
            ->orderByDesc('last_message_at')
            ->limit(100)
            ->get();

        return response()->json([
            'data' => MessageThreadResource::for('client', $threads),
            'unread' => (int) $threads->sum('unread_count'),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'matter_id' => ['required', 'integer'],
            'subject' => ['required', 'string', 'max:255'],
            ...$this->messageRules(),
        ]);

        $matter = $this->client($request)->matters()->findOrFail($validated['matter_id']);
        $thread = $this->messaging->start($matter, $this->client($request), $validated['subject'], $validated['body'], $validated['file'] ?? null);

        return response()->json($this->payload($thread), 201);
    }

    public function show(Request $request, int $thread): JsonResponse
    {
        $model = $this->own($request, $thread);
        $this->messaging->markRead($model, 'client');

        return response()->json($this->payload($model));
    }

    public function reply(Request $request, int $thread): JsonResponse
    {
        $model = $this->own($request, $thread);
        $validated = $request->validate($this->messageRules());
        $this->messaging->post($model, $this->client($request), $validated['body'], $validated['file'] ?? null);

        return response()->json($this->payload($model), 201);
    }

    private function messageRules(): array
    {
        return [
            'body' => ['required', 'string', 'max:10000'],
            'file' => ['nullable', StoreMatterFile::rule()],
        ];
    }

    private function own(Request $request, int $id): MessageThread
    {
        return MessageThread::where('client_id', $this->client($request)->id)->findOrFail($id);
    }

    private function client(Request $request): Client
    {
        return $request->user('client');
    }

    private function payload(MessageThread $thread): array
    {
        $thread->load(['matter:id,reference,title', 'messages.sender', 'messages.attachment']);

        return (new MessageThreadResource($thread, 'client'))->resolve();
    }
}
