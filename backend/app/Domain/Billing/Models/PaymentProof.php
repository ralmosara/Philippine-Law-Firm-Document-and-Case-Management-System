<?php

namespace App\Domain\Billing\Models;

use App\Casts\DateOnly;
use App\Domain\Documents\Models\MatterFile;
use App\Domain\Matters\Models\Client;
use App\Models\Traits\Auditable;
use App\Models\Traits\HasTenantScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentProof extends Model
{
    use Auditable, HasTenantScope;

    /** How the client says they paid (a subset of the payment methods). */
    public const METHODS = ['bank_transfer' => 'Bank transfer or deposit', 'e_wallet' => 'GCash, Maya or other e-wallet', 'check' => 'Cheque', 'cash' => 'Cash', 'other' => 'Other'];

    protected $fillable = ['firm_id', 'invoice_id', 'client_id', 'matter_file_id', 'amount_cents', 'paid_on', 'method', 'reference', 'note'];

    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return ['amount_cents' => 'integer', 'paid_on' => DateOnly::class, 'reviewed_at' => 'datetime'];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(MatterFile::class, 'matter_file_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
