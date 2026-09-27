<?php

namespace App\Domain\Trust\Models;

use App\Domain\Trust\Enums\TrustTransactionType;
use App\Models\Traits\AppendOnly;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrustTransaction extends Model
{
    use AppendOnly;

    public const UPDATED_AT = null;

    protected $fillable = ['trust_account_id', 'type', 'amount_cents', 'balance_after_cents', 'reference', 'description', 'created_by'];

    protected function casts(): array
    {
        return [
            'type' => TrustTransactionType::class,
            'amount_cents' => 'integer',
            'balance_after_cents' => 'integer',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(TrustAccount::class, 'trust_account_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
