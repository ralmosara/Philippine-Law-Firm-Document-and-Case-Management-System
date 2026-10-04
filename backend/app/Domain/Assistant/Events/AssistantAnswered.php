<?php

namespace App\Domain\Assistant\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/** The assistant finished (or gave up on) an answer: the asking lawyer's screen refetches it. */
class AssistantAnswered implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public readonly int $userId, public readonly int $conversationId, public readonly int $matterId) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("App.Models.User.{$this->userId}")];
    }

    public function broadcastAs(): string
    {
        return 'assistant.answered';
    }

    public function broadcastWith(): array
    {
        return ['conversation_id' => $this->conversationId, 'matter_id' => $this->matterId];
    }
}
