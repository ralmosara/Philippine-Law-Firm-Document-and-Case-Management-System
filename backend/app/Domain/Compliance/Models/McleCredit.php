<?php

namespace App\Domain\Compliance\Models;

use App\Casts\DateOnly;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class McleCredit extends Model
{
    protected $fillable = ['user_id', 'period_id', 'title', 'provider', 'subject_area', 'units', 'date_earned', 'certificate_number'];

    protected function casts(): array
    {
        return [
            'units' => 'decimal:2',
            'date_earned' => DateOnly::class,
        ];
    }

    public function lawyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(McleCompliancePeriod::class, 'period_id');
    }
}
