<?php

namespace App\Domain\Deadlines\Models;

use App\Casts\DateOnly;
use App\Domain\Deadlines\Enums\DeadlineKind;
use App\Domain\Deadlines\Enums\DeadlineStatus;
use App\Domain\Deadlines\Enums\ReminderStage;
use App\Domain\Deadlines\Enums\TaskPriority;
use App\Domain\Deadlines\Enums\TaskProgress;
use App\Domain\Matters\Models\Matter;
use App\Models\Traits\Auditable;
use App\Models\Traits\HasTenantScope;
use App\Models\User;
use Database\Factories\MatterDeadlineFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MatterDeadline extends Model
{
    /** @use HasFactory<MatterDeadlineFactory> */
    use Auditable, HasFactory, HasTenantScope;

    protected $fillable = [
        'firm_id',
        'matter_id',
        'deadline_rule_id',
        'assigned_to',
        'kind',
        'title',
        'trigger_date',
        'due_date',
        'due_time',
        'location',
        'notes',
        'progress',
        'priority',
    ];

    protected $attributes = [
        'status' => 'pending',
        'progress' => 'todo',
        'priority' => 'normal',
    ];

    protected function casts(): array
    {
        return [
            'kind' => DeadlineKind::class,
            'status' => DeadlineStatus::class,
            'progress' => TaskProgress::class,
            'priority' => TaskPriority::class,
            'last_reminder_stage' => ReminderStage::class,
            'trigger_date' => DateOnly::class,
            'due_date' => DateOnly::class,
            'completed_at' => 'datetime',
        ];
    }

    public function scopePending(Builder $query): void
    {
        $query->where('status', DeadlineStatus::Pending->value);
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(DeadlineRule::class, 'deadline_rule_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function events(): HasMany
    {
        return $this->hasMany(DeadlineEvent::class)->latest('id');
    }

    public function logEvent(string $type, ?User $user = null, array $payload = []): DeadlineEvent
    {
        return $this->events()->create([
            'event_type' => $type,
            'user_id' => $user?->id,
            'payload' => $payload ?: null,
        ]);
    }

    protected static function newFactory(): MatterDeadlineFactory
    {
        return MatterDeadlineFactory::new();
    }
}
