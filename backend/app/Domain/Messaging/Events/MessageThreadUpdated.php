<?php

namespace App\Domain\Messaging\Events;

use App\Domain\Messaging\Models\MessageThread;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A client conversation changed (a new message, or one side read it). Open
 * screens refetch the thread and the inboxes. Carries no message text:
 * what was said is only ever read through the API, with its access checks.
 */
class MessageThreadUpdated implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets;

    /** @param 'message'|'read' $change */
    public function __construct(public readonly MessageThread $thread, public readonly string $change) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("message-thread.{$this->thread->id}"),
            new PrivateChannel("firm.{$this->thread->firm_id}.messages"),
            new PrivateChannel("portal-client.{$this->thread->client_id}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'thread.updated';
    }

    public function broadcastWith(): array
    {
        return ['thread_id' => $this->thread->id, 'matter_id' => $this->thread->matter_id, 'change' => $this->change];
    }
}
