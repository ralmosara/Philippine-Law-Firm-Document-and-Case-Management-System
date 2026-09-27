<?php

namespace App\Models;

use App\Models\Traits\AppendOnly;
use App\Models\Traits\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AuditLog extends Model
{
    use AppendOnly, HasTenantScope;

    public const UPDATED_AT = null;

    protected $fillable = ['firm_id', 'actor_type', 'actor_id', 'action', 'subject_type', 'subject_id', 'changes', 'ip_address'];

    protected function casts(): array
    {
        return ['changes' => 'array'];
    }

    public function actor(): MorphTo
    {
        return $this->morphTo();
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public static function record(string $action, ?int $firmId, ?Model $actor = null, ?Model $subject = null, ?array $changes = null): self
    {
        return static::create([
            'firm_id' => $firmId,
            'actor_type' => $actor?->getMorphClass(),
            'actor_id' => $actor?->getKey(),
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'changes' => $changes,
            'ip_address' => app()->runningInConsole() ? null : request()->ip(),
        ]);
    }
}
