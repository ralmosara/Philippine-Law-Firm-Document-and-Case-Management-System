<?php

namespace App\Domain\Matters\Models;

use App\Models\Traits\HasTenantScope;
use Illuminate\Database\Eloquent\Model;

/**
 * A checklist of standard tasks for a case type (e.g. "Annulment"), applied
 * automatically when a matter of that type is opened.
 *
 * `tasks` is a list of {title: string, days_offset: int, kind?: string}.
 */
class CaseWorkflowTemplate extends Model
{
    use HasTenantScope;

    protected $fillable = ['firm_id', 'case_type', 'name', 'tasks', 'is_active'];

    protected function casts(): array
    {
        return [
            'tasks' => 'array',
            'is_active' => 'boolean',
        ];
    }
}
