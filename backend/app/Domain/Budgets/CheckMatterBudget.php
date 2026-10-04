<?php

namespace App\Domain\Budgets;

use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Re-checks a matter's budget after its time or expenses change. */
class CheckMatterBudget implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(public readonly int $firmId, public readonly int $matterId) {}

    public function handle(TenantContext $tenant, MatterBudgets $budgets): void
    {
        $tenant->runAs($this->firmId, fn () => $budgets->check($this->matterId));
    }

    /** Only for matters that have a budget; called when time or expenses are saved or deleted. */
    public static function after(object $model): void
    {
        if ($model->matter_id && MatterBudget::query()->where('matter_id', $model->matter_id)->exists()) {
            static::dispatch((int) $model->firm_id, (int) $model->matter_id)->afterCommit();
        }
    }
}
