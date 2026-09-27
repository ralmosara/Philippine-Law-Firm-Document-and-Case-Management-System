<?php

namespace App\Domain\Documents\Requests;

use App\Domain\Documents\Models\MatterFile;
use App\Models\Traits\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One document on a request: waiting, uploaded, accepted or sent back. */
class DocumentRequestItem extends Model
{
    use HasTenantScope;

    public const PENDING = 'pending';

    public const UPLOADED = 'uploaded';

    public const ACCEPTED = 'accepted';

    public const REJECTED = 'rejected';

    protected $fillable = ['firm_id', 'document_request_id', 'label', 'description', 'required', 'position'];

    protected $attributes = [
        'status' => self::PENDING,
        'required' => true,
    ];

    protected function casts(): array
    {
        return [
            'required' => 'boolean',
            'uploaded_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    /** Still needs something from the client. */
    public function awaitingClient(): bool
    {
        return in_array($this->status, [self::PENDING, self::REJECTED], true);
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(DocumentRequest::class, 'document_request_id');
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(MatterFile::class, 'matter_file_id');
    }
}
