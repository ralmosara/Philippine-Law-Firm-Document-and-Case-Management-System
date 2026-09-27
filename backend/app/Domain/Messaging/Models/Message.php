<?php

namespace App\Domain\Messaging\Models;

use App\Domain\Documents\Models\MatterFile;
use App\Models\Traits\AppendOnly;
use App\Models\Traits\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One message in a thread, from a staff member (sender_type "user") or the
 * client ("client"). Never edited or deleted.
 */
class Message extends Model
{
    use AppendOnly, HasTenantScope;

    public const UPDATED_AT = null;

    protected $fillable = ['firm_id', 'thread_id', 'sender_type', 'sender_id', 'body', 'matter_file_id'];

    public function thread(): BelongsTo
    {
        return $this->belongsTo(MessageThread::class, 'thread_id');
    }

    public function sender(): MorphTo
    {
        return $this->morphTo();
    }

    public function attachment(): BelongsTo
    {
        return $this->belongsTo(MatterFile::class, 'matter_file_id');
    }

    public function isFromClient(): bool
    {
        return $this->sender_type === 'client';
    }
}
