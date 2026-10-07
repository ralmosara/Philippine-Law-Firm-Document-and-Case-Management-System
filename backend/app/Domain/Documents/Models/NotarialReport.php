<?php

namespace App\Domain\Documents\Models;

use App\Casts\DateOnly;
use App\Models\Traits\Auditable;
use App\Models\Traits\HasTenantScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A notary's monthly certified copy of the register, and whether it went to the clerk of court. */
class NotarialReport extends Model
{
    use Auditable, HasTenantScope;

    protected $fillable = ['firm_id', 'notary_id', 'period', 'entries'];

    protected function casts(): array
    {
        return ['period' => DateOnly::class, 'entries' => 'integer', 'submitted_at' => 'datetime', 'last_reminded_on' => DateOnly::class];
    }

    public function notary(): BelongsTo
    {
        return $this->belongsTo(User::class, 'notary_id');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }
}
