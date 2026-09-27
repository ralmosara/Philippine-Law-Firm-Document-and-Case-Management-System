<?php

namespace App\Domain\Billing\Collections;

use App\Domain\Billing\Models\Invoice;
use App\Models\Traits\HasTenantScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A payment reminder e-mailed to a client about one invoice. */
class InvoiceReminder extends Model
{
    use HasTenantScope;

    public const DUE_SOON = 'due_soon';

    public const OVERDUE_7 = 'overdue_7';

    public const OVERDUE_30 = 'overdue_30';

    public const MANUAL = 'manual';

    public const LABELS = [
        self::DUE_SOON => 'Due soon',
        self::OVERDUE_7 => 'A week overdue',
        self::OVERDUE_30 => 'A month overdue',
        self::MANUAL => 'Sent by hand',
    ];

    public $timestamps = false;

    protected $fillable = ['firm_id', 'invoice_id', 'stage', 'balance_cents', 'sent_by', 'sent_at'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime', 'balance_cents' => 'integer'];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }
}
