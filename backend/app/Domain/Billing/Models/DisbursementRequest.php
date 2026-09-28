<?php

namespace App\Domain\Billing\Models;

use App\Casts\DateOnly;
use App\Domain\Billing\Enums\ExpenseCategory;
use App\Domain\Matters\Models\Matter;
use App\Domain\Trust\Models\TrustAccount;
use App\Models\Traits\Auditable;
use App\Models\Traits\HasTenantScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A cash advance for case costs, from request to liquidation. */
class DisbursementRequest extends Model
{
    use Auditable, HasTenantScope;

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const RELEASED = 'released';

    public const LIQUIDATED = 'liquidated';

    public const CANCELLED = 'cancelled';

    public const FROM_FIRM = 'firm';

    public const FROM_TRUST = 'trust';

    /** Days after release to liquidate, moved to the next working day. */
    public const LIQUIDATE_WITHIN_DAYS = 7;

    protected $fillable = ['firm_id', 'matter_id', 'requested_by', 'category', 'description', 'amount_cents', 'needed_by', 'source', 'trust_account_id'];

    protected $attributes = [
        'status' => self::PENDING,
        'source' => self::FROM_FIRM,
    ];

    protected function casts(): array
    {
        return [
            'category' => ExpenseCategory::class,
            'amount_cents' => 'integer',
            'spent_cents' => 'integer',
            'returned_cents' => 'integer',
            'needed_by' => DateOnly::class,
            'liquidation_due_on' => DateOnly::class,
            'decided_at' => 'datetime',
            'released_at' => 'datetime',
            'liquidated_at' => 'datetime',
            'matter_id' => 'integer',
            'requested_by' => 'integer',
            'trust_account_id' => 'integer',
        ];
    }

    public function isOverdue(): bool
    {
        return $this->status === self::RELEASED && $this->liquidation_due_on?->lt(today());
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function releaser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by');
    }

    public function trustAccount(): BelongsTo
    {
        return $this->belongsTo(TrustAccount::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }
}
