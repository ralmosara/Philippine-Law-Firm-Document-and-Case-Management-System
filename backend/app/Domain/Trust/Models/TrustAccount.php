<?php

namespace App\Domain\Trust\Models;

use App\Domain\Matters\Models\Client;
use App\Domain\Matters\Models\Matter;
use App\Models\Traits\HasTenantScope;
use Database\Factories\TrustAccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Client funds held in trust (Canon III, Sec. 49-50 CPRA: a lawyer holds
 * client money in trust and must account for it). The balance column is a
 * cache of the ledger; only TrustLedgerService may change it.
 */
class TrustAccount extends Model
{
    /** @use HasFactory<TrustAccountFactory> */
    use HasFactory, HasTenantScope;

    protected $fillable = ['firm_id', 'client_id', 'matter_id', 'account_number'];

    protected $attributes = [
        'balance_cents' => 0,
        'status' => 'open',
    ];

    protected function casts(): array
    {
        return ['balance_cents' => 'integer'];
    }

    protected static function booted(): void
    {
        static::creating(function (TrustAccount $account) {
            $account->account_number ??= static::nextAccountNumber($account->firm_id);
        });
    }

    public static function nextAccountNumber(int $firmId): string
    {
        $last = static::withoutGlobalScopes()
            ->where('firm_id', $firmId)
            ->where('account_number', 'like', 'TA-%')
            ->orderByDesc('account_number')
            ->value('account_number');

        $sequence = $last ? ((int) substr($last, 3)) + 1 : 1;

        return 'TA-'.str_pad((string) $sequence, 5, '0', STR_PAD_LEFT);
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(TrustTransaction::class)->orderByDesc('id');
    }

    protected static function newFactory(): TrustAccountFactory
    {
        return TrustAccountFactory::new();
    }
}
