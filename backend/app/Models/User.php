<?php

namespace App\Models;

use App\Domain\Compliance\Models\McleCredit;
use App\Domain\Matters\Models\Matter;
use App\Enums\Role;
use App\Models\Traits\Auditable;
use App\Models\Traits\HasTenantScope;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * A firm staff member (lawyer, paralegal or administrative staff).
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use Auditable, HasApiTokens, HasFactory, HasTenantScope, Notifiable;

    protected $fillable = [
        'firm_id',
        'name',
        'email',
        'password',
        'role',
        'ibp_number',
        'roll_number',
        'ptr_number',
        'mcle_compliance_number',
        'mobile_number',
        'hourly_rate_cents',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'role' => Role::class,
            'hourly_rate_cents' => 'integer',
            'is_active' => 'boolean',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /** Whether sign-in requires an authenticator code as well as the password. */
    public function hasTwoFactorEnabled(): bool
    {
        // Raw attributes: a freshly created model may not carry these columns yet.
        return isset($this->attributes['two_factor_confirmed_at'], $this->attributes['two_factor_secret']);
    }

    public function matters(): HasMany
    {
        return $this->hasMany(Matter::class, 'responsible_lawyer_id');
    }

    public function mcleCredits(): HasMany
    {
        return $this->hasMany(McleCredit::class);
    }

    /** Route SMS notifications (see SemaphoreChannel) to the user's mobile. */
    public function routeNotificationForSms(): ?string
    {
        return $this->mobile_number;
    }
}
