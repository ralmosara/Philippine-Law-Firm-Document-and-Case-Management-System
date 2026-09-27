<?php

namespace App\Domain\Imports\Models;

use App\Models\Traits\HasTenantScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DataImport extends Model
{
    use HasTenantScope;

    public const PREVIEWED = 'previewed';

    public const COMMITTED = 'committed';

    public const UNDONE = 'undone';

    /** Undo is offered for this long after committing. */
    public const UNDO_DAYS = 7;

    protected $fillable = ['firm_id', 'type', 'filename', 'status', 'rows', 'summary', 'created_ids', 'created_by'];

    protected function casts(): array
    {
        return [
            'rows' => 'array',
            'summary' => 'array',
            'created_ids' => 'array',
            'committed_at' => 'datetime',
            'undone_at' => 'datetime',
        ];
    }

    public function canUndo(): bool
    {
        return $this->status === self::COMMITTED && $this->committed_at?->isAfter(now()->subDays(self::UNDO_DAYS));
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
