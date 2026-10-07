<?php

namespace App\Domain\Compliance\Models;

use App\Models\Traits\Auditable;
use App\Models\Traits\HasTenantScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A person's written consent to the firm acting despite a possible conflict of interest. */
class ConflictWaiver extends Model
{
    use Auditable, HasTenantScope;

    protected $fillable = ['firm_id', 'conflict_check_id', 'signer_name', 'signer_email', 'content', 'content_sha256', 'created_by'];

    protected $hidden = ['token_hash', 'signature_image'];

    protected $attributes = ['status' => 'sent'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime', 'expires_at' => 'datetime', 'responded_at' => 'datetime'];
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function check(): BelongsTo
    {
        return $this->belongsTo(ConflictCheck::class, 'conflict_check_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
