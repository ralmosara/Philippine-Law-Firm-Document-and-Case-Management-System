<?php

namespace App\Models\Traits;

use App\Models\AuditLog;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;

/**
 * Records create/update/delete of the model in the firm's audit trail.
 * Hidden attributes (passwords, tokens) are never written to the log.
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(fn (Model $model) => static::recordAudit($model, 'created', $model->attributesToArray()));

        static::updated(function (Model $model) {
            $changes = collect($model->getChanges())
                ->except(['updated_at', ...$model->getHidden()])
                ->all();

            if ($changes !== []) {
                $original = collect($model->getOriginal())->only(array_keys($changes))->all();
                static::recordAudit($model, 'updated', ['before' => $original, 'after' => $changes]);
            }
        });

        static::deleted(fn (Model $model) => static::recordAudit($model, 'deleted'));
    }

    protected static function recordAudit(Model $model, string $action, ?array $changes = null): void
    {
        $context = app(TenantContext::class);

        AuditLog::record(
            action: $action,
            firmId: $model->getAttribute('firm_id') ?? $context->firmId(),
            actor: $context->actor(),
            subject: $model,
            changes: $changes,
        );
    }
}
