<?php

namespace App\Domain\Matters\Models;

use App\Domain\Billing\Models\Invoice;
use App\Domain\Trust\Models\TrustAccount;
use App\Models\Traits\Auditable;
use App\Models\Traits\HasTenantScope;
use App\Support\Localization\PortalLocale;
use Database\Factories\ClientFactory;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * A firm's client. Also the authenticatable principal for the client portal
 * (the `client` guard) when portal access has been enabled.
 */
class Client extends Authenticatable implements HasLocalePreference
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
        'aliases',
        'portal_enabled',
        'locale',
        'hearing_reminders',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'calendar_token_hash',
    ];

    protected $attributes = [
        'portal_enabled' => false,
        'locale' => 'en',
        'privacy_notice_version' => null,
        'privacy_accepted_at' => null,
        'anonymized_at' => null,
        'aliases' => null,
        'hearing_reminders' => true,
        'calendar_token_hash' => null,
        'calendar_created_at' => null,
        'calendar_accessed_at' => null,
    ];

    protected function casts(): array
    {
        return [
            'portal_enabled' => 'boolean',
            'password' => 'hashed',
            'last_portal_login_at' => 'datetime',
            'privacy_notice_version' => 'integer',
            'privacy_accepted_at' => 'datetime',
            'anonymized_at' => 'datetime',
            'calendar_created_at' => 'datetime',
            'hearing_reminders' => 'boolean',
            'calendar_accessed_at' => 'datetime',
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

    /** Text messages (hearing reminders) go to the client's mobile number. */
    public function routeNotificationForSms(): ?string
    {
        return filled($this->phone) ? (string) $this->phone : null;
    }

    /** Emails to the client go out in the language they chose in the portal. */
    public function preferredLocale(): string
    {
        return PortalLocale::normalize($this->locale);
    }
}
