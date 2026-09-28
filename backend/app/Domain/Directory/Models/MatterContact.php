<?php

namespace App\Domain\Directory\Models;

use App\Domain\Matters\Models\Matter;
use App\Models\Traits\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A directory contact's part in one matter. */
class MatterContact extends Model
{
    use HasTenantScope;

    protected $fillable = ['firm_id', 'matter_id', 'contact_id', 'role', 'notes'];

    protected function casts(): array
    {
        return ['matter_id' => 'integer', 'contact_id' => 'integer'];
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
