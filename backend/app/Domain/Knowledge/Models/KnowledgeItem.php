<?php

namespace App\Domain\Knowledge\Models;

use App\Domain\Documents\Models\Document;
use App\Domain\Matters\Models\Matter;
use App\Models\Traits\Auditable;
use App\Models\Traits\HasTenantScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** One entry in the firm's knowledge bank. */
class KnowledgeItem extends Model
{
    use Auditable, HasTenantScope, SoftDeletes;

    public const KINDS = [
        'pleading' => 'Pleading',
        'clause' => 'Clause',
        'jurisprudence' => 'Jurisprudence note',
        'form' => 'Form',
        'note' => 'Research note',
    ];

    protected $fillable = [
        'firm_id', 'kind', 'title', 'citation', 'doctrine', 'body', 'practice_area', 'tags',
        'source_document_id', 'source_matter_id', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'source_document_id' => 'integer',
            'source_matter_id' => 'integer',
            'created_by' => 'integer',
        ];
    }

    public function sourceDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'source_document_id');
    }

    public function sourceMatter(): BelongsTo
    {
        return $this->belongsTo(Matter::class, 'source_matter_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
