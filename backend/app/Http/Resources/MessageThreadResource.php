<?php

namespace App\Http\Resources;

use App\Domain\Messaging\Models\Message;
use App\Domain\Messaging\Models\MessageThread;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * A thread for either side. `viewer` ("staff" or "client") decides which
 * messages are "mine" and hides staff-only details from clients.
 *
 * @mixin MessageThread
 */
class MessageThreadResource extends JsonResource
{
    public function __construct(MessageThread $resource, private readonly string $viewer = 'staff')
    {
        parent::__construct($resource);
    }

    public static function for(string $viewer, iterable $threads): array
    {
        return collect($threads)->map(fn (MessageThread $t) => (new self($t, $viewer))->resolve())->all();
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'subject' => $this->subject,
            'last_message_at' => $this->last_message_at?->toIso8601String(),
            'unread_count' => $this->whenHas('unread_count', fn () => (int) $this->unread_count),
            'matter' => $this->whenLoaded('matter', fn () => ['id' => $this->matter->id, 'reference' => $this->matter->reference, 'title' => $this->matter->title]),
            'client' => $this->when($this->viewer === 'staff', fn () => $this->whenLoaded('client', fn () => ['id' => $this->client->id, 'name' => $this->client->name])),
            'preview' => $this->whenLoaded('latestMessage', fn () => $this->latestMessage ? Str::limit($this->latestMessage->body, 120) : null),
            'messages' => $this->whenLoaded('messages', fn () => $this->messages->map(fn (Message $m) => [
                'id' => $m->id,
                'body' => $m->body,
                'mine' => $m->isFromClient() === ($this->viewer === 'client'),
                'from_client' => $m->isFromClient(),
                'sender_name' => $m->sender?->name,
                'attachment' => $m->attachment ? ['id' => $m->attachment->id, 'name' => $m->attachment->original_name, 'size_bytes' => $m->attachment->size_bytes] : null,
                'created_at' => $m->created_at?->toIso8601String(),
            ])),
        ];
    }
}
