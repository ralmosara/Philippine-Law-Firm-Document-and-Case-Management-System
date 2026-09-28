<?php

namespace App\Domain\Business\Models;

use App\Models\Traits\HasTenantScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An entry in a prospect's history: a note, a call, a meeting, or a change of stage. Never edited. */
class ProspectEvent extends Model
{
    use HasTenantScope;

    public const UPDATED_AT = null;

    protected $fillable = ['firm_id', 'prospect_id', 'type', 'from_stage', 'to_stage', 'body', 'created_by'];

    protected function casts(): array
    {
        return ['prospect_id' => 'integer'];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
