<?php

namespace App\Models\Scopes;

use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Restricts every query on a firm-owned model to the current tenant.
 *
 * With no tenant in context (console commands, queue workers, login lookups)
 * the scope is inert; those code paths must scope explicitly or use
 * TenantContext::runAs().
 */
class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);

        if ($context->hasFirm()) {
            $builder->where($model->qualifyColumn('firm_id'), $context->firmId());
        }
    }
}
