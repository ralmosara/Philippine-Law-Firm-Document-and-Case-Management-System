<?php

namespace App\Domain\Evidence\Models;

use App\Casts\DateOnly;
use App\Domain\Documents\Models\MatterFile;
use App\Domain\Matters\Models\Matter;
use App\Models\Traits\Auditable;
use App\Models\Traits\HasTenantScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One exhibit in a case, as marked for the client's side or the other side. */
class Exhibit extends Model
{
    use Auditable, HasTenantScope;

    public const OURS = 'ours';

    public const ADVERSE = 'adverse';

    public const STATUSES = ['marked', 'offered', 'admitted', 'denied', 'withdrawn'];

    protected $fillable = [
        'firm_id', 'matter_id', 'side', 'marking', 'description', 'purpose', 'witness', 'matter_file_id',
        'marked_on', 'status', 'objection', 'ruling', 'ruled_on', 'notes', 'created_by',
    ];

    protected $attributes = [
        'status' => 'marked',
    ];

    protected function casts(): array
    {
        return [
            'marked_on' => DateOnly::class,
            'ruled_on' => DateOnly::class,
            'matter_id' => 'integer',
            'matter_file_id' => 'integer',
        ];
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(MatterFile::class, 'matter_file_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
