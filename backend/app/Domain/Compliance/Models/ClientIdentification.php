<?php

namespace App\Domain\Compliance\Models;

use App\Casts\DateOnly;
use App\Domain\Matters\Models\Client;
use App\Models\Traits\Auditable;
use App\Models\Traits\HasTenantScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientIdentification extends Model
{
    use Auditable, HasTenantScope;

    /** Government-issued IDs commonly accepted for identification. */
    public const TYPES = ['Passport', "Driver's license", 'UMID', 'PhilSys national ID', 'PRC ID', 'Postal ID', "Voter's ID", 'SSS ID', 'GSIS e-card', 'TIN ID', 'IBP ID', 'Senior citizen ID', 'SEC certificate of registration', 'DTI certificate of registration', 'Other'];

    protected $fillable = ['firm_id', 'client_id', 'id_type', 'id_number', 'issued_on', 'expires_on', 'path', 'original_name', 'mime_type', 'sha256', 'notes', 'verified_by', 'verified_at'];

    protected $hidden = ['path'];

    protected function casts(): array
    {
        return ['issued_on' => DateOnly::class, 'expires_on' => DateOnly::class, 'verified_at' => 'datetime'];
    }

    public function isExpired(): bool
    {
        return $this->expires_on !== null && $this->expires_on->lt(today());
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
