<?php

namespace App\Domain\Feedback;

use App\Domain\Matters\Models\Client;
use App\Domain\Matters\Models\Matter;
use App\Models\Traits\Auditable;
use App\Models\Traits\HasTenantScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A client's feedback on a closed matter: asked for at closing, answered in the portal. */
class MatterFeedback extends Model
{
    use Auditable, HasTenantScope;

    protected $table = 'matter_feedback';

    /** Ratings at or below this alert the firm, to follow up with the client. */
    public const LOW = 2;

    protected $fillable = ['firm_id', 'matter_id', 'client_id', 'requested_at'];

    protected $attributes = [
        'rating' => null,
        'comment' => null,
        'responded_at' => null,
        'followed_up_at' => null,
        'follow_up_note' => null,
    ];

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'requested_at' => 'datetime',
            'responded_at' => 'datetime',
            'followed_up_at' => 'datetime',
        ];
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function followedUpBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'followed_up_by');
    }

    public function isLow(): bool
    {
        return $this->rating !== null && $this->rating <= self::LOW;
    }
}
