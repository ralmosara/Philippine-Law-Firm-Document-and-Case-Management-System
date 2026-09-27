<?php

namespace App\Domain\Intake\Models;

use App\Domain\Compliance\Models\ConflictCheck;
use App\Domain\Matters\Models\Client;
use App\Domain\Matters\Models\Matter;
use App\Models\Traits\HasTenantScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A prospective client's request for a consultation, from the public intake page. */
class IntakeRequest extends Model
{
    use HasTenantScope;

    public const NEW = 'new';

    public const SCHEDULED = 'scheduled';

    public const ACCEPTED = 'accepted';

    public const DECLINED = 'declined';

    protected $fillable = [
        'firm_id', 'name', 'email', 'phone', 'client_type', 'case_type', 'description',
        'opposing_parties', 'preferred_times', 'consent_at', 'ip_address', 'conflict_check_ids', 'conflict_status',
    ];

    protected $attributes = [
        'status' => self::NEW,
    ];

    protected function casts(): array
    {
        return [
            'opposing_parties' => 'array',
            'preferred_times' => 'array',
            'conflict_check_ids' => 'array',
            'consent_at' => 'datetime',
            'consultation_at' => 'datetime',
        ];
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::NEW, self::SCHEDULED], true);
    }

    /** @return Collection<int, ConflictCheck> */
    public function conflictChecks(): Collection
    {
        return ConflictCheck::whereIn('id', $this->conflict_check_ids ?? [])->orderBy('id')->get();
    }

    public function assignedLawyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_lawyer_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }
}
