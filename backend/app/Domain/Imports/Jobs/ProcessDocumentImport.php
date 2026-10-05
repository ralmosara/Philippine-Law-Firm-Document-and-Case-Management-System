<?php

namespace App\Domain\Imports\Jobs;

use App\Domain\Imports\DocumentImports;
use App\Domain\Imports\Models\DocumentImport;
use App\Jobs\Concerns\RunsOnHeavyQueue;
use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Files the next batch of an archive's documents, then queues itself until all are done. */
class ProcessDocumentImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RunsOnHeavyQueue;

    public int $tries = 3;

    /** Each batch scans and extracts up to 40 files; keep well under the heavy queue's limit. */
    public int $timeout = 600;

    public function __construct(public readonly int $firmId, public readonly int $importId)
    {
        $this->useHeavyQueue();
    }

    public function handle(TenantContext $tenant, DocumentImports $imports): void
    {
        $more = $tenant->runAs($this->firmId, function () use ($imports) {
            $import = DocumentImport::query()->find($this->importId);

            return $import !== null && $import->status === DocumentImport::IMPORTING && $imports->processBatch($import);
        });

        if ($more) {
            static::dispatch($this->firmId, $this->importId);
        }
    }
}
