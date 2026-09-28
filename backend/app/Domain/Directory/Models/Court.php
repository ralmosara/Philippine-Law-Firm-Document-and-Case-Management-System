<?php

namespace App\Domain\Directory\Models;

use App\Domain\Matters\Models\Matter;
use App\Models\Traits\Auditable;
use App\Models\Traits\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A court or tribunal, down to the branch. */
class Court extends Model
{
    use Auditable, HasTenantScope;

    public const LEVELS = [
        'sc' => 'Supreme Court',
        'ca' => 'Court of Appeals',
        'sandiganbayan' => 'Sandiganbayan',
        'cta' => 'Court of Tax Appeals',
        'rtc' => 'Regional Trial Court',
        'metc' => 'Metropolitan Trial Court',
        'mtcc' => 'Municipal Trial Court in Cities',
        'mtc' => 'Municipal Trial Court',
        'mctc' => 'Municipal Circuit Trial Court',
        'sharia' => "Shari'a Court",
        'nlrc' => 'NLRC / Labor Arbiter',
        'agency' => 'Quasi-judicial agency',
        'other' => 'Other',
    ];

    protected $fillable = ['firm_id', 'level', 'name', 'branch', 'station', 'address', 'email', 'phone', 'notes'];

    /** As matters and pleadings write it: "Regional Trial Court, Makati City". */
    public function label(): string
    {
        return $this->name.($this->station ? ", {$this->station}" : '');
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    public function matters(): HasMany
    {
        return $this->hasMany(Matter::class);
    }
}
