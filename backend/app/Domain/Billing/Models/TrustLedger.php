<?php

namespace App\Domain\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrustLedger extends Model
{
    protected $guarded = [];

    protected $casts = [
        'transaction_date' => 'date',
        'amount' => 'float',
    ];

    public function trustAccount(): BelongsTo
    {
        return $this->belongsTo(TrustAccount::class);
    }
}
