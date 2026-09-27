<?php

namespace App\Domain\Billing\Models;

use App\Domain\Matters\Models\Client;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TrustAccount extends Model
{
    protected $guarded = [];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function ledgers(): HasMany
    {
        return $this->hasMany(TrustLedger::class);
    }

    /**
     * Get the current balance of the trust account.
     */
    public function getBalanceAttribute(): float
    {
        $deposits = $this->ledgers()->where('transaction_type', 'deposit')->sum('amount');
        $withdrawals = $this->ledgers()->where('transaction_type', 'withdrawal')->sum('amount');

        return $deposits - $withdrawals;
    }
}
