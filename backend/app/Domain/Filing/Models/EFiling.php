<?php

namespace App\Domain\Filing\Models;

use App\Domain\Deadlines\Models\MatterDeadline;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\MatterFile;
use App\Domain\Matters\Models\Matter;
use App\Models\Traits\Auditable;
use App\Models\Traits\HasTenantScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One electronic filing: the package prepared for the court and what became of it. */
class EFiling extends Model
{
    use Auditable, HasTenantScope;

    protected $table = 'e_filings';

    public const VIA = [
        'email' => 'E-mail to the court',
        'ecourt' => 'Court e-filing portal',
        'in_person' => 'In person, with an electronic copy',
        'courier' => 'Courier or registered mail, with an electronic copy',
        'other' => 'Other',
    ];

    protected $fillable = ['firm_id', 'matter_id', 'document_id', 'title', 'items', 'package_file_id', 'page_count', 'size_bytes', 'checks', 'created_by'];

    protected $attributes = [
        'status' => 'prepared',
    ];

    protected function casts(): array
    {
        return [
            'items' => 'array',
            'checks' => 'array',
            'page_count' => 'integer',
            'size_bytes' => 'integer',
            'filed_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'matter_id' => 'integer',
            'package_file_id' => 'integer',
            'acknowledgment_file_id' => 'integer',
            'deadline_id' => 'integer',
        ];
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(MatterFile::class, 'package_file_id');
    }

    public function acknowledgmentFile(): BelongsTo
    {
        return $this->belongsTo(MatterFile::class, 'acknowledgment_file_id');
    }

    public function deadline(): BelongsTo
    {
        return $this->belongsTo(MatterDeadline::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function filer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'filed_by');
    }
}
