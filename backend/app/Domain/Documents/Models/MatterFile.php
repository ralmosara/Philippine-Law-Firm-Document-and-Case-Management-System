<?php

namespace App\Domain\Documents\Models;

use App\Domain\Matters\Models\Matter;
use App\Models\Traits\Auditable;
use App\Models\Traits\HasTenantScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An uploaded file on a matter: evidence, scanned pleadings, signed copies,
 * correspondence. The bytes live on a private disk; the SHA-256 recorded at
 * upload lets the firm show a file has not changed since.
 *
 * Deleting only hides the record; the file is kept for retention.
 */
class MatterFile extends Model
{
    use Auditable, HasTenantScope, SoftDeletes;

    /** Upload limit in kilobytes (keep nginx client_max_body_size and PHP limits above it). */
    public const MAX_KILOBYTES = 20 * 1024;

    /** Accepted extensions, checked against the file's actual content. */
    public const ALLOWED_TYPES = [
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'rtf', 'txt', 'csv',
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'tif', 'tiff', 'eml', 'msg', 'mp3', 'm4a', 'mp4',
    ];

    protected $fillable = [
        'firm_id', 'matter_id', 'uploaded_by', 'original_name', 'path', 'mime_type',
        'size_bytes', 'sha256', 'description', 'shared_with_client', 'scan_status', 'scanned_at',
    ];

    // The extracted text can be large; it is only read by search.
    protected $hidden = ['path', 'content_text', 'search_vector'];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'shared_with_client' => 'boolean',
            'scanned_at' => 'datetime',
        ];
    }

    public static function disk(): string
    {
        return config('filesystems.matter_files', 'local');
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
