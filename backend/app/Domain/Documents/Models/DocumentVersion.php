<?php

namespace App\Domain\Documents\Models;

use App\Models\Traits\AppendOnly;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentVersion extends Model
{
    use AppendOnly;

    public const UPDATED_AT = null;

    protected $fillable = ['document_id', 'version_number', 'content', 'change_summary', 'created_by'];

    protected function casts(): array
    {
        return ['version_number' => 'integer'];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
