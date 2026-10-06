<?php

namespace App\Domain\Compliance\Models;

use App\Models\Traits\Auditable;
use App\Models\Traits\HasTenantScope;
use Illuminate\Database\Eloquent\Model;

/** A natural person who ultimately owns or controls a juridical client. */
class BeneficialOwner extends Model
{
    use Auditable, HasTenantScope;

    protected $table = 'client_beneficial_owners';

    protected $fillable = ['firm_id', 'client_id', 'name', 'ownership_bps', 'position', 'nationality', 'notes'];

    protected function casts(): array
    {
        return ['ownership_bps' => 'integer'];
    }
}
