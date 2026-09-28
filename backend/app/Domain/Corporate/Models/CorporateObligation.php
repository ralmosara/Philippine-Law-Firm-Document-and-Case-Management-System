<?php

namespace App\Domain\Corporate\Models;

use App\Casts\DateOnly;
use App\Domain\Matters\Models\Client;
use App\Models\Traits\Auditable;
use App\Models\Traits\HasTenantScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One yearly SEC or BIR obligation of a client company. */
class CorporateObligation extends Model
{
    use Auditable, HasTenantScope;

    public const PENDING = 'pending';

    public const DONE = 'done';

    public const NOT_APPLICABLE = 'not_applicable';

    public const KINDS = [
        'annual_meeting' => "Annual stockholders' meeting",
        'gis' => 'General Information Sheet (SEC)',
        'afs' => 'Audited financial statements (SEC)',
        'annual_itr' => 'Annual income tax return (BIR)',
        'custom' => 'Other',
    ];

    protected $fillable = ['firm_id', 'client_id', 'kind', 'title', 'year', 'due_on', 'notes'];

    protected $attributes = [
        'status' => self::PENDING,
    ];

    protected function casts(): array
    {
        return ['due_on' => DateOnly::class, 'done_on' => DateOnly::class, 'year' => 'integer', 'client_id' => 'integer'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }
}
