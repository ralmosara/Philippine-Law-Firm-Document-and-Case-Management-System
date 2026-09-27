<?php

namespace App\Domain\Messaging\Models;

use App\Domain\Matters\Models\Client;
use App\Domain\Matters\Models\Matter;
use App\Models\Traits\HasTenantScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** A conversation between a client and the firm about one matter. */
class MessageThread extends Model
{
    use HasTenantScope;

    protected $fillable = ['firm_id', 'matter_id', 'client_id', 'subject'];

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
            'staff_last_read_id' => 'integer',
            'client_last_read_id' => 'integer',
        ];
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'thread_id')->orderBy('id');
    }

    public function latestMessage(): HasOne
    {
        return $this->hasOne(Message::class, 'thread_id')->latestOfMany('id');
    }

    /**
     * Adds `unread_count`: messages from the other side since this side last
     * read the thread. $side is who is reading: "staff" or "client".
     */
    public function scopeWithUnreadCount(Builder $query, string $side): void
    {
        $senderType = $side === 'staff' ? 'client' : 'user';
        $readColumn = $side === 'staff' ? 'staff_last_read_id' : 'client_last_read_id';

        $query->withCount(['messages as unread_count' => fn (Builder $q) => $q
            ->where('sender_type', $senderType)
            ->whereColumn('messages.id', '>', "message_threads.{$readColumn}")]);
    }
}
