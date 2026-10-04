<?php

namespace App\Domain\EInvoicing;

use App\Domain\Billing\Models\Invoice;
use App\Models\Traits\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One e-invoice document (an issued invoice, or its cancellation) and what became of it. */
class EInvoice extends Model
{
    use HasTenantScope;

    public const INVOICE = 'invoice';

    public const CANCELLATION = 'cancellation';

    public const PENDING = 'pending';

    /** Kept on file by the "record" driver: no provider is connected yet. */
    public const RECORDED = 'recorded';

    public const SUBMITTED = 'submitted';

    public const ACCEPTED = 'accepted';

    public const REJECTED = 'rejected';

    /** Could not be sent (provider down, network); retried automatically. */
    public const FAILED = 'failed';

    /** Nothing more to do. */
    public const SETTLED = [self::RECORDED, self::ACCEPTED];

    protected $fillable = ['firm_id', 'invoice_id', 'kind', 'status', 'driver', 'payload', 'payload_sha256', 'due_on'];

    protected $attributes = [
        'status' => self::PENDING,
        'attempts' => 0,
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attempts' => 'integer',
            'due_on' => 'date',
            'submitted_at' => 'datetime',
            'accepted_at' => 'datetime',
            'overdue_notified_at' => 'datetime',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function canRetry(): bool
    {
        return in_array($this->status, [self::FAILED, self::REJECTED], true);
    }
}
