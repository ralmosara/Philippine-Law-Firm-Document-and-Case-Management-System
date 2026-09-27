<?php

namespace App\Domain\Privacy\Models;

use App\Casts\DateOnly;
use App\Domain\Matters\Models\Client;
use App\Models\Traits\Auditable;
use App\Models\Traits\HasTenantScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A request from a data subject exercising a right under the Data Privacy
 * Act (Sec. 16): to see, correct, erase or object to the processing of
 * their personal data, or to receive a copy they can take elsewhere.
 */
class DataSubjectRequest extends Model
{
    use Auditable, HasTenantScope;

    public const TYPES = [
        'access' => 'See a copy of my data',
        'correction' => 'Correct my data',
        'erasure' => 'Delete my data',
        'objection' => 'Stop a use of my data',
        'portability' => 'Get my data in a portable file',
    ];

    public const OPEN = 'open';

    public const COMPLETED = 'completed';

    public const DENIED = 'denied';

    /** Target response time, in days; the firm's DPO may set a stricter one in practice. */
    public const RESPONSE_DAYS = 15;

    protected $fillable = ['firm_id', 'client_id', 'requester_name', 'requester_email', 'type', 'details', 'source', 'due_on'];

    protected $attributes = [
        'status' => self::OPEN,
    ];

    protected function casts(): array
    {
        return [
            'due_on' => DateOnly::class,
            'resolved_at' => 'datetime',
        ];
    }

    public function isOverdue(): bool
    {
        return $this->status === self::OPEN && $this->due_on->isPast() && ! $this->due_on->isToday();
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }
}
