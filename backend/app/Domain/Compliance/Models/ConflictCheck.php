<?php

namespace App\Domain\Compliance\Models;

use App\Domain\Compliance\Enums\ConflictCheckStatus;
use App\Models\Traits\HasTenantScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConflictCheck extends Model
{
    use HasTenantScope;

    protected $fillable = ['firm_id', 'requested_by', 'search_term', 'matches', 'match_count', 'status'];

    protected function casts(): array
    {
        return [
            'matches' => 'array',
            'match_count' => 'integer',
            'status' => ConflictCheckStatus::class,
            'resolved_at' => 'datetime',
        ];
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
