<?php

namespace App\Domain\Assistant\Models;

use App\Models\Traits\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiMessage extends Model
{
    use HasTenantScope;

    public const PENDING = 'pending';

    public const COMPLETE = 'complete';

    public const FAILED = 'failed';

    protected $fillable = ['firm_id', 'conversation_id', 'role', 'content', 'status'];

    protected function casts(): array
    {
        return [
            'sources' => 'array',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cache_read_tokens' => 'integer',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'conversation_id');
    }
}
