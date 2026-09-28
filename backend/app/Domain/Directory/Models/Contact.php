<?php

namespace App\Domain\Directory\Models;

use App\Models\Traits\Auditable;
use App\Models\Traits\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Someone the firm deals with outside its clients: judges, clerks, prosecutors, opposing counsel, experts. */
class Contact extends Model
{
    use Auditable, HasTenantScope;

    public const KINDS = [
        'judge' => 'Judge',
        'clerk_of_court' => 'Clerk of court',
        'prosecutor' => 'Prosecutor',
        'opposing_counsel' => 'Opposing counsel',
        'expert' => 'Expert witness',
        'sheriff' => 'Sheriff / process server',
        'other' => 'Other',
    ];

    /** What a contact can be on a matter. */
    public const ROLES = [
        'judge' => 'Presiding judge',
        'clerk_of_court' => 'Clerk of court',
        'prosecutor' => 'Prosecutor',
        'opposing_counsel' => 'Opposing counsel',
        'expert' => 'Expert witness',
        'sheriff' => 'Sheriff / process server',
        'other' => 'Other',
    ];

    protected $fillable = ['firm_id', 'kind', 'name', 'title', 'organization', 'court_id', 'email', 'phone', 'address', 'roll_number', 'notes', 'is_active'];

    protected $attributes = [
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'court_id' => 'integer'];
    }

    public function displayName(): string
    {
        return trim(($this->title ? "{$this->title} " : '').$this->name);
    }

    public function court(): BelongsTo
    {
        return $this->belongsTo(Court::class);
    }

    public function matterLinks(): HasMany
    {
        return $this->hasMany(MatterContact::class);
    }
}
