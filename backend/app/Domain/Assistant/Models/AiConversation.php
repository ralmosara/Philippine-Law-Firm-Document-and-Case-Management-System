<?php

namespace App\Domain\Assistant\Models;

use App\Domain\Matters\Models\Matter;
use App\Models\Traits\HasTenantScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A lawyer's private conversation with the assistant about one matter. */
class AiConversation extends Model
{
    use HasTenantScope;

    protected $fillable = ['firm_id', 'matter_id', 'user_id', 'title'];

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(AiMessage::class, 'conversation_id')->orderBy('id');
    }
}
