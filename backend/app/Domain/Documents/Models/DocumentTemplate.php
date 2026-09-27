<?php

namespace App\Domain\Documents\Models;

use App\Domain\Documents\Services\DocumentMerger;
use App\Models\Traits\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class DocumentTemplate extends Model
{
    use HasTenantScope, SoftDeletes;

    protected $fillable = ['firm_id', 'name', 'category', 'body'];

    /** Merge fields are derived from the body, so they can never drift from it. */
    protected $appends = ['merge_fields'];

    public function getMergeFieldsAttribute(): array
    {
        return app(DocumentMerger::class)->fieldsIn($this->body ?? '');
    }
}
