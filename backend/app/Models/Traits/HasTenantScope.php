<?php

namespace App\Models\Traits;

use App\Domain\Matters\Models\Firm;
use App\Models\Scopes\TenantScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Marks a model as owned by a firm: reads are scoped to the current tenant,
 * and new rows are stamped with it. Writing a row into another firm while a
 * tenant is in context is a programming error and fails loudly.
 */
trait HasTenantScope
{
    public static function bootHasTenantScope(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (Model $model) {
            $context = app(TenantContext::class);

            if (! $context->hasFirm()) {
                return;
            }

            if ($model->getAttribute('firm_id') === null) {
                $model->setAttribute('firm_id', $context->firmId());
            } elseif ((int) $model->getAttribute('firm_id') !== $context->firmId()) {
                throw new LogicException(sprintf(
                    'Refusing to create %s for firm %d while acting as firm %d.',
                    class_basename($model), $model->getAttribute('firm_id'), $context->firmId(),
                ));
            }
        });
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }
}
