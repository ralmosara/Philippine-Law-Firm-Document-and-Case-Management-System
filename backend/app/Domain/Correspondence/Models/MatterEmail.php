<?php

namespace App\Domain\Correspondence\Models;

use App\Domain\Documents\Models\MatterFile;
use App\Domain\Matters\Models\Client;
use App\Domain\Matters\Models\Matter;
use App\Models\Traits\HasTenantScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An email sent or forwarded to a matter's address, or uploaded as an .eml. */
class MatterEmail extends Model
{
    use HasTenantScope;

    public const QUEUED = 'queued';

    public const REVIEW = 'review';

    public const FILED = 'filed';

    public const REJECTED = 'rejected';

    protected $fillable = [
        'firm_id', 'matter_id', 'status', 'source', 'message_id', 'from_email', 'from_name', 'to', 'cc', 'subject',
        'sent_at', 'body_text', 'attachments', 'raw_path', 'sender_user_id', 'sender_client_id',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'attachments' => 'array',
            'matter_id' => 'integer',
            'eml_file_id' => 'integer',
            'sender_user_id' => 'integer',
            'sender_client_id' => 'integer',
            'reviewed_by' => 'integer',
        ];
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function emlFile(): BelongsTo
    {
        return $this->belongsTo(MatterFile::class, 'eml_file_id');
    }

    public function senderUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }

    public function senderClient(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'sender_client_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
