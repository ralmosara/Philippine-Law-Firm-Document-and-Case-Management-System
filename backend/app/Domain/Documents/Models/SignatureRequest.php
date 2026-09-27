<?php

namespace App\Domain\Documents\Models;

use App\Domain\Documents\Enums\SignatureStatus;
use App\Domain\Matters\Models\Client;
use App\Models\Traits\Auditable;
use App\Models\Traits\HasTenantScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A request for a client to sign a final document electronically in the
 * portal, and afterwards the evidence of that signature: who signed, how,
 * when, from where, and the SHA-256 of exactly what they saw.
 */
class SignatureRequest extends Model
{
    use Auditable, HasTenantScope;

    protected $fillable = [
        'firm_id', 'document_id', 'document_version_id', 'client_id', 'requested_by',
        'message', 'content_sha256', 'expires_at',
    ];

    protected $attributes = [
        'status' => 'pending',
    ];

    // Kept out of audit-log diffs; the resource exposes it explicitly.
    protected $hidden = ['signature_image'];

    protected function casts(): array
    {
        return [
            'status' => SignatureStatus::class,
            'expires_at' => 'datetime',
            'responded_at' => 'datetime',
        ];
    }

    /** Pending and not yet expired: the client can still sign or decline. */
    public function isOpen(): bool
    {
        return $this->status === SignatureStatus::Pending && ! $this->isExpired();
    }

    public function isExpired(): bool
    {
        return $this->status === SignatureStatus::Pending && $this->expires_at?->isPast();
    }

    public function scopeOpen(Builder $query): void
    {
        $query->where('status', SignatureStatus::Pending->value)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'document_version_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
