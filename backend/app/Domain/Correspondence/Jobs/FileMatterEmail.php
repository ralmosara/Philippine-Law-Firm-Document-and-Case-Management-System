<?php

namespace App\Domain\Correspondence\Jobs;

use App\Domain\Correspondence\InboundEmails;
use App\Domain\Correspondence\Models\MatterEmail;
use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Files an email with its matter. Retried with growing delays: the usual
 * failure is the virus scanner being briefly unavailable, and nothing is
 * stored unscanned.
 */
class FileMatterEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 6;

    /** @var list<int> */
    public array $backoff = [60, 300, 900, 1800, 3600];

    public function __construct(public readonly int $emailId) {}

    public function handle(InboundEmails $emails, TenantContext $tenant): void
    {
        $email = MatterEmail::withoutGlobalScopes()->find($this->emailId);
        if (! $email || $email->status !== MatterEmail::QUEUED) {
            return;
        }

        $tenant->runAs($email->firm_id, fn () => $emails->file(MatterEmail::findOrFail($email->id)));
    }
}
