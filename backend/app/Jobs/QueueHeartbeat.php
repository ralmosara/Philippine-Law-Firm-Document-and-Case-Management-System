<?php

namespace App\Jobs;

use App\Jobs\Concerns\RunsOnHeavyQueue;
use App\Support\Ops\SystemHealth;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Queued every minute on each queue. When a worker stops, its heartbeat
 * goes stale and the health check fails, instead of reminders silently
 * piling up unsent.
 */
class QueueHeartbeat implements ShouldQueue
{
    use Queueable, RunsOnHeavyQueue;

    public int $tries = 1;

    public function __construct(public readonly string $lane = 'default')
    {
        if ($lane === 'heavy') {
            $this->useHeavyQueue();
        }
    }

    public function handle(): void
    {
        Cache::put(SystemHealth::queueKey($this->lane), now()->toIso8601String(), now()->addDay());
    }
}
