<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

/** The signed-in user's notification center (the bell). */
class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'unread' => $user->unreadNotifications()->count(),
            'data' => $user->notifications()->latest()->limit(50)->get()->map(fn (DatabaseNotification $n) => [
                'id' => $n->id,
                ...$n->data,
                'read_at' => $n->read_at?->toIso8601String(),
                'created_at' => $n->created_at?->toIso8601String(),
            ]),
            'realtime' => $this->realtime(),
        ]);
    }

    public function read(Request $request, string $id): JsonResponse
    {
        $request->user()->notifications()->whereKey($id)->firstOrFail()->markAsRead();

        return response()->json(['unread' => $request->user()->unreadNotifications()->count()]);
    }

    public function readAll(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return response()->json(['unread' => 0]);
    }

    /**
     * How the browser reaches the WebSocket server, when there is one: the
     * same origin, path /app (proxied to Reverb). Null means poll instead.
     */
    private function realtime(): ?array
    {
        if (config('broadcasting.default') !== 'reverb' || blank(config('broadcasting.connections.reverb.key'))) {
            return null;
        }

        return ['key' => config('broadcasting.connections.reverb.key')];
    }
}
