<?php

namespace App\Domain\Documents\Models;

use App\Domain\Matters\Models\Matter;
use App\Models\Traits\AppendOnly;
use App\Models\Traits\HasTenantScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line in a notary public's register. The register is a public record;
 * entries are never edited or removed.
 */
class NotarialEntry extends Model
{
    use AppendOnly, HasTenantScope;

    public const ACT_TYPES = ['acknowledgment', 'jurat', 'oath', 'copy_certification', 'signature_witnessing'];

    protected $fillable = [
        'firm_id',
        'notary_id',
        'matter_id',
        'document_id',
        'doc_number',
        'page_number',
        'book_number',
        'series_year',
        'act_type',
        'document_title',
        'principal_name',
        'competent_evidence',
        'fee_cents',
        'notarized_at',
    ];

    protected function casts(): array
    {
        return [
            'doc_number' => 'integer',
            'page_number' => 'integer',
            'book_number' => 'integer',
            'series_year' => 'integer',
            'fee_cents' => 'integer',
            'notarized_at' => 'datetime',
        ];
    }

    public function notary(): BelongsTo
    {
        return $this->belongsTo(User::class, 'notary_id');
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
