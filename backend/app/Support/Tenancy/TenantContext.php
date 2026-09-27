<?php

namespace App\Support\Tenancy;

use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * The firm (tenant) the current request or job is acting on behalf of.
 *
 * Set once per request by the SetTenantContext middleware from the
 * authenticated principal. Every firm-owned model reads it through
 * TenantScope, so tenant isolation does not depend on each query
 * remembering a where-clause.
 */
class TenantContext
{
    private ?int $firmId = null;

    private ?Model $actor = null;

    public function set(int $firmId, ?Model $actor = null): void
    {
        $this->firmId = $firmId;
        $this->actor = $actor;
    }

    public function clear(): void
    {
        $this->firmId = null;
        $this->actor = null;
    }

    public function firmId(): ?int
    {
        return $this->firmId;
    }

    public function hasFirm(): bool
    {
        return $this->firmId !== null;
    }

    /** The user or portal client performing the action, for audit logging. */
    public function actor(): ?Model
    {
        return $this->actor;
    }

    /**
     * Run a callback as the given firm, restoring the previous context after.
     * Used by queued jobs and console commands that act on one firm at a time.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function runAs(int $firmId, Closure $callback, ?Model $actor = null): mixed
    {
        [$previousFirm, $previousActor] = [$this->firmId, $this->actor];
        $this->set($firmId, $actor);

        try {
            return $callback();
        } finally {
            $this->firmId = $previousFirm;
            $this->actor = $previousActor;
        }
    }
}
