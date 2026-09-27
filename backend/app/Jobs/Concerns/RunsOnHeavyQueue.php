<?php

namespace App\Jobs\Concerns;

/**
 * For slow jobs (OCR, calls to the AI assistant): send them to the "heavy"
 * queue, served by its own worker, so a 300-page scan can never delay a
 * deadline reminder waiting on the default queue.
 */
trait RunsOnHeavyQueue
{
    protected function useHeavyQueue(): void
    {
        $this->onConnection(config('queue.heavy.connection'));
        $this->onQueue(config('queue.heavy.queue'));
    }
}
