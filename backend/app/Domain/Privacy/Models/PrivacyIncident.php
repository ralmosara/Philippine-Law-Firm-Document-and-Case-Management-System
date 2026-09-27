<?php

namespace App\Domain\Privacy\Models;

use App\Models\Traits\Auditable;
use App\Models\Traits\HasTenantScope;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A personal data breach or security incident. When there is a real risk
 * of serious harm, NPC Circular 16-03 requires notifying the National
 * Privacy Commission, and the affected people, within 72 hours of
 * knowing about it.
 */
class PrivacyIncident extends Model
{
    use Auditable, HasTenantScope;

    public const NOTIFY_WITHIN_HOURS = 72;

    public const STATUSES = ['open', 'contained', 'closed'];

    protected $fillable = [
        'firm_id', 'title', 'description', 'discovered_at', 'occurred_at', 'affected_count', 'data_involved',
        'sensitive', 'notifiable', 'npc_notified_at', 'subjects_notified_at', 'actions_taken', 'status', 'reported_by',
    ];

    protected $attributes = [
        'status' => 'open',
        'sensitive' => false,
        'notifiable' => true,
    ];

    protected function casts(): array
    {
        return [
            'discovered_at' => 'datetime',
            'occurred_at' => 'datetime',
            'npc_notified_at' => 'datetime',
            'subjects_notified_at' => 'datetime',
            'sensitive' => 'boolean',
            'notifiable' => 'boolean',
            'affected_count' => 'integer',
        ];
    }

    public function notifyBy(): CarbonImmutable
    {
        return CarbonImmutable::instance($this->discovered_at)->addHours(self::NOTIFY_WITHIN_HOURS);
    }

    /** Still owes the NPC a notification. */
    public function npcNotificationPending(): bool
    {
        return $this->notifiable && $this->npc_notified_at === null;
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }
}
