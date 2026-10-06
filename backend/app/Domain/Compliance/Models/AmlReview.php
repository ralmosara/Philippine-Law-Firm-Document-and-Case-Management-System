<?php

namespace App\Domain\Compliance\Models;

use App\Casts\DateOnly;
use App\Domain\Matters\Models\Client;
use App\Models\Traits\Auditable;
use App\Models\Traits\HasTenantScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A client's trust deposits on one day reached the firm's threshold: someone decides whether to report. */
class AmlReview extends Model
{
    use Auditable, HasTenantScope;

    protected $fillable = ['firm_id', 'client_id', 'day', 'amount_cents', 'trust_transaction_ids'];

    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return ['day' => DateOnly::class, 'amount_cents' => 'integer', 'trust_transaction_ids' => 'array', 'decided_at' => 'datetime'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
