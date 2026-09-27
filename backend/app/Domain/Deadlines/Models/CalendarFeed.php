<?php

namespace App\Domain\Deadlines\Models;

use App\Models\Traits\HasTenantScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A staff member's private calendar subscription URL (only its hash is kept). */
class CalendarFeed extends Model
{
    use HasTenantScope;

    protected $fillable = ['firm_id', 'user_id', 'token_hash', 'scope', 'show_details'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'show_details' => 'boolean',
            'last_accessed_at' => 'datetime',
        ];
    }

    public static function hashToken(string $token): string
    {
        return hash_hmac('sha256', $token, (string) config('app.key'));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
