<?php

namespace App\Domain\Matters\Models;

use App\Domain\Matters\Enums\PartyRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MatterParty extends Model
{
    protected $fillable = ['role', 'name', 'counsel_name', 'contact', 'notes'];

    protected function casts(): array
    {
        return ['role' => PartyRole::class];
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }
}
