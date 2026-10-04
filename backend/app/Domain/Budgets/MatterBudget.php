<?php

namespace App\Domain\Budgets;

use App\Domain\Matters\Models\Matter;
use App\Models\Traits\Auditable;
use App\Models\Traits\HasTenantScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A matter's budget, in pesos (`total` in centavos: billable fees, plus
 * billable expenses when `include_expenses`) or in hours (`total` in
 * minutes of billable time). `stages` optionally splits it by case stage.
 */
class MatterBudget extends Model
{
    use Auditable, HasTenantScope;

    public const AMOUNT = 'amount';

    public const HOURS = 'hours';

    protected $fillable = ['firm_id', 'matter_id', 'basis', 'total', 'include_expenses', 'stages', 'shared_with_client', 'notes', 'created_by', 'updated_by'];

    protected $attributes = [
        'include_expenses' => true,
        'shared_with_client' => false,
        'stages' => null,
        'notes' => null,
    ];

    protected function casts(): array
    {
        return [
            'total' => 'integer',
            'include_expenses' => 'boolean',
            'stages' => 'array',
            'shared_with_client' => 'boolean',
            'alerted_80_at' => 'datetime',
            'alerted_100_at' => 'datetime',
        ];
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function isHours(): bool
    {
        return $this->basis === self::HOURS;
    }
}
