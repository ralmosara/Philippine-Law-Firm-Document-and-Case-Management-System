<?php

namespace App\Domain\Business\Models;

use App\Casts\DateOnly;
use App\Domain\Compliance\Models\ConflictCheck;
use App\Domain\Matters\Models\Client;
use App\Domain\Matters\Models\Matter;
use App\Models\Traits\Auditable;
use App\Models\Traits\HasTenantScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A prospective client, from first contact to a signed engagement or a loss. */
class Prospect extends Model
{
    use Auditable, HasTenantScope;

    public const STAGES = [
        'lead' => 'Lead',
        'consultation' => 'Consultation',
        'proposal' => 'Proposal sent',
        'engagement_sent' => 'Engagement letter sent',
        'won' => 'Engaged',
        'lost' => 'Lost',
    ];

    /** Stages a prospect moves through before it is won or lost. */
    public const OPEN = ['lead', 'consultation', 'proposal', 'engagement_sent'];

    public const SOURCES = [
        'referral' => 'Referral',
        'existing_client' => 'Existing client',
        'website' => 'Website / online intake',
        'event' => 'Event or talk',
        'social_media' => 'Social media',
        'walk_in' => 'Walk-in or call',
        'other' => 'Other',
    ];

    protected $fillable = [
        'firm_id', 'name', 'organization', 'client_type', 'email', 'phone', 'source', 'referred_by', 'case_type', 'description',
        'opposing_parties', 'estimated_value_cents', 'owner_id', 'next_step', 'next_step_on', 'intake_request_id', 'conflict_check_ids',
    ];

    protected $attributes = [
        'stage' => 'lead',
        'client_type' => 'individual',
    ];

    protected function casts(): array
    {
        return [
            'opposing_parties' => 'array',
            'conflict_check_ids' => 'array',
            'estimated_value_cents' => 'integer',
            'owner_id' => 'integer',
            'client_id' => 'integer',
            'matter_id' => 'integer',
            'next_step_on' => DateOnly::class,
            'reminded_on' => DateOnly::class,
            'proposal_sent_on' => DateOnly::class,
            'engagement_sent_on' => DateOnly::class,
            'engagement_signed_on' => DateOnly::class,
            'closed_at' => 'datetime',
        ];
    }

    public function isOpen(): bool
    {
        return in_array($this->stage, self::OPEN, true);
    }

    /** @return Collection<int, ConflictCheck> */
    public function conflictChecks(): Collection
    {
        return ConflictCheck::whereIn('id', $this->conflict_check_ids ?? [])->orderBy('id')->get();
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(ProspectEvent::class);
    }
}
