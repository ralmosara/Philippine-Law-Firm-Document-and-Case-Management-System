<?php

namespace App\Domain\Trust\Models;

use App\Casts\DateOnly;
use App\Models\Traits\Auditable;
use App\Models\Traits\HasTenantScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrustReconciliation extends Model
{
    use Auditable, HasTenantScope;

    protected $fillable = ['firm_id', 'period_end', 'bank_account', 'statement_balance_cents', 'deposits_in_transit', 'outstanding_checks', 'notes', 'prepared_by'];

    protected function casts(): array
    {
        return [
            'period_end' => DateOnly::class,
            'statement_balance_cents' => 'integer',
            'deposits_in_transit' => 'array',
            'outstanding_checks' => 'array',
            'adjusted_bank_cents' => 'integer',
            'ledger_cents' => 'integer',
            'client_total_cents' => 'integer',
            'exceptions' => 'array',
            'signed_off_at' => 'datetime',
        ];
    }

    /** The adjusted bank balance less the ledger: 0 when they agree. */
    public function difference(): int
    {
        return $this->adjusted_bank_cents - $this->ledger_cents;
    }

    /** All three figures agree and no account is below zero. */
    public function balances(): bool
    {
        return $this->difference() === 0 && $this->ledger_cents === $this->client_total_cents && $this->exceptions === [];
    }

    public function isSignedOff(): bool
    {
        return $this->signed_off_at !== null;
    }

    public function preparer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    public function signer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'signed_off_by');
    }
}
