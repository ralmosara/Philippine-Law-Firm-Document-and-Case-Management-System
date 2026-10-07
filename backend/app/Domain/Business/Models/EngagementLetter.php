<?php

namespace App\Domain\Business\Models;

use App\Domain\Matters\Enums\FeeArrangement;
use App\Models\Traits\Auditable;
use App\Models\Traits\HasTenantScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EngagementLetter extends Model
{
    use Auditable, HasTenantScope;

    public const OPEN = ['draft', 'sent'];

    protected $fillable = ['firm_id', 'prospect_id', 'fee_arrangement', 'fixed_fee_cents', 'acceptance_fee_cents', 'appearance_fee_cents', 'contingency_basis_points', 'scope', 'content', 'created_by'];

    protected $hidden = ['token_hash', 'signature_image'];

    protected $attributes = ['status' => 'draft'];

    protected function casts(): array
    {
        return [
            'fee_arrangement' => FeeArrangement::class,
            'fixed_fee_cents' => 'integer',
            'acceptance_fee_cents' => 'integer',
            'appearance_fee_cents' => 'integer',
            'contingency_basis_points' => 'integer',
            'sent_at' => 'datetime',
            'expires_at' => 'datetime',
            'responded_at' => 'datetime',
        ];
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function prospect(): BelongsTo
    {
        return $this->belongsTo(Prospect::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
