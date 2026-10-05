<?php

namespace App\Domain\Imports\Models;

use App\Models\Traits\HasTenantScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One ZIP of documents being brought over from the firm's old files. */
class DocumentImport extends Model
{
    use HasTenantScope;

    public const UPLOADING = 'uploading';

    public const PREVIEWED = 'previewed';

    public const IMPORTING = 'importing';

    public const DONE = 'done';

    public const FAILED = 'failed';

    public const UNDONE = 'undone';

    /** Undo is offered this long after the import finished. */
    public const UNDO_DAYS = 7;

    protected $fillable = ['firm_id', 'filename', 'size_bytes', 'created_by'];

    protected $attributes = [
        'status' => self::UPLOADING,
        'received_bytes' => 0,
        'received_chunks' => 0,
        'folders' => null,
        'summary' => null,
        'error' => null,
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'received_bytes' => 'integer',
            'received_chunks' => 'integer',
            'folders' => 'array',
            'summary' => 'array',
            'committed_at' => 'datetime',
            'finished_at' => 'datetime',
            'undone_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Where the ZIP is kept while it is uploaded and imported (private disk). */
    public function archivePath(): string
    {
        return "imports/firm-{$this->firm_id}/document-import-{$this->id}.zip";
    }

    public function canUndo(): bool
    {
        return $this->status === self::DONE && $this->finished_at?->gt(now()->subDays(self::UNDO_DAYS));
    }
}
