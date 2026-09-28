<?php

namespace App\Domain\Tax\Models;

use App\Casts\DateOnly;
use App\Models\Traits\Auditable;
use App\Models\Traits\HasTenantScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One BIR return the firm must file for one period, and whether it was. */
class TaxFiling extends Model
{
    use Auditable, HasTenantScope;

    public const PENDING = 'pending';

    public const FILED = 'filed';

    public const NOT_APPLICABLE = 'not_applicable';

    protected $fillable = ['firm_id', 'form', 'period', 'due_on'];

    protected $attributes = [
        'status' => self::PENDING,
    ];

    protected function casts(): array
    {
        return [
            'due_on' => DateOnly::class,
            'filed_on' => DateOnly::class,
        ];
    }

    public function filer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'filed_by');
    }
}
