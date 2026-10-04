<?php

use App\Domain\Matters\Models\Client;
use App\Domain\Messaging\Models\MessageThread;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

// Each staff member's private channel: live notifications for the bell.
Broadcast::channel('App.Models.User.{id}', fn (User $user, int $id) => $user->id === $id && $user->is_active);

// A client conversation: the firm's staff (who can all read its threads) and that client.
Broadcast::channel('message-thread.{id}', function (User|Client $who, int $id) {
    $thread = MessageThread::withoutGlobalScopes()->find($id, ['id', 'firm_id', 'client_id']);

    return $thread !== null && match (true) {
        $who instanceof User => $who->is_active && (int) $who->firm_id === (int) $thread->firm_id,
        default => $who->portal_enabled && (int) $who->id === (int) $thread->client_id,
    };
}, ['guards' => ['sanctum', 'client']]);

// The firm's message inbox (unread counts).
Broadcast::channel('firm.{firmId}.messages', fn (User $user, int $firmId) => $user->is_active && (int) $user->firm_id === $firmId);

// A portal client's own inbox.
Broadcast::channel('portal-client.{id}', fn (Client $client, int $id) => $client->portal_enabled && (int) $client->id === $id, ['guards' => ['client']]);
