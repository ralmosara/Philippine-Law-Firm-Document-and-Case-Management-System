<?php

namespace App\Domain\Matters\Models;

use App\Domain\Billing\Models\Invoice;
use App\Domain\Trust\Models\TrustAccount;
use App\Models\Traits\Auditable;
use App\Models\Traits\HasTenantScope;
use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * A firm's client. Also the authenticatable principal for the client portal
 * (the `client` guard) when portal access has been enabled.
 */
class Client extends Authenticatable
{
    /** @use HasFactory<ClientFactory> */
    use Auditable, HasFactory, HasTenantScope, Notifiable, SoftDeletes;

    protected $fillable = [
        'firm_id',
        'type',
        'name',
        'tin',
        'email',
        'phone',
        'address',
        'notes',
        'portal_enabled',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $attributes = [
        'portal_enabled' => false,
        'privacy_notice_version' => null,
        'privacy_accepted_at' => null,
        'anonymized_at' => null,
    ];

    protected function casts(): array
    {
        return [
            'portal_enabled' => 'boolean',
            'password' => 'hashed',
            'last_portal_login_at' => 'datetime',
        ];
    }

    public function matters(): HasMany
    {
        return $this->hasMany(Matter::class);
    }

    public function trustAccounts(): HasMany
    {
        return $this->hasMany(TrustAccount::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    protected static function newFactory(): ClientFactory
    {
        return ClientFactory::new();
    }
}
