<?php

namespace App\Domain\Deadlines\Models;

use App\Models\Traits\AppendOnly;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeadlineEvent extends Model
{
    use AppendOnly;

    public const UPDATED_AT = null;

    protected $fillable = ['matter_deadline_id', 'event_type', 'user_id', 'payload'];

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }

    public function deadline(): BelongsTo
    {
        return $this->belongsTo(MatterDeadline::class, 'matter_deadline_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
