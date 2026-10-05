<?php

namespace App\Domain\Billing\Models;

use App\Casts\DateOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceLine extends Model
{
    protected $fillable = ['invoice_id', 'kind', 'time_entry_id', 'expense_id', 'work_date', 'description', 'minutes', 'rate_cents', 'amount_cents', 'original_amount_cents', 'adjustment_reason'];

    protected function casts(): array
    {
        return [
            'work_date' => DateOnly::class,
            'minutes' => 'integer',
            'rate_cents' => 'integer',
            'amount_cents' => 'integer',
            'original_amount_cents' => 'integer',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
