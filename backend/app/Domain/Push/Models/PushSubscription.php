<?php

namespace App\Domain\Push\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A device a staff member receives notifications on. Belongs to a user,
 * not a firm: users are already scoped to their firm, and the keys are
 * the browser's, useless to anyone else.
 */
class PushSubscription extends Model
{
    protected $fillable = ['user_id', 'endpoint', 'endpoint_hash', 'p256dh', 'auth', 'user_agent'];

    protected $hidden = ['p256dh', 'auth'];

    protected function casts(): array
    {
        return ['user_id' => 'integer', 'last_sent_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
