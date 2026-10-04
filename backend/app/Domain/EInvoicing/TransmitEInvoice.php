<?php

namespace App\Domain\EInvoicing;

use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Sends one e-invoice, retrying over several hours while the provider is unreachable. */
class TransmitEInvoice implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 6;

    public function __construct(public readonly int $firmId, public readonly int $eInvoiceId) {}

    /** 1 min, 5 min, 15 min, 1 h, 4 h: well within the BIR's three days. */
    public function backoff(): array
    {
        return [60, 300, 900, 3600, 14400];
    }

    public function handle(TenantContext $tenant, EInvoicing $eInvoicing): void
    {
        $tenant->runAs($this->firmId, function () use ($eInvoicing) {
            $eInvoice = EInvoice::query()->find($this->eInvoiceId);
            if ($eInvoice !== null) {
                $eInvoicing->transmit($eInvoice);
            }
        });
    }
}
