<?php

namespace App\Domain\Matters\Models;

use App\Models\User;
use Database\Factories\FirmFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Firm extends Model
{
    /** @use HasFactory<FirmFactory> */
    use HasFactory;

    protected $fillable = ['name', 'tin', 'address', 'email', 'phone', 'vat_registered', 'require_two_factor', 'slug', 'intake_enabled', 'intake_message', 'ai_enabled', 'default_withholding_bps'];

    protected function casts(): array
    {
        return ['default_withholding_bps' => 'integer', 'vat_registered' => 'boolean', 'require_two_factor' => 'boolean', 'intake_enabled' => 'boolean', 'ai_enabled' => 'boolean'];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    protected static function newFactory(): FirmFactory
    {
        return FirmFactory::new();
    }
}
