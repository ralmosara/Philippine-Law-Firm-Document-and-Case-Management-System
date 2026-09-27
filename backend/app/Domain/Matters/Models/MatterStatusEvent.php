<?php

namespace App\Domain\Matters\Models;

use App\Domain\Matters\Enums\MatterStatus;
use App\Models\Traits\AppendOnly;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MatterStatusEvent extends Model
{
    use AppendOnly;

    public const UPDATED_AT = null;

    protected $fillable = ['matter_id', 'from_status', 'to_status', 'changed_by', 'reason'];

    protected function casts(): array
    {
        return [
            'from_status' => MatterStatus::class,
            'to_status' => MatterStatus::class,
        ];
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
