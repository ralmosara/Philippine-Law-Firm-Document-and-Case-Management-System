<?php

namespace App\Domain\Matters\Actions;

use App\Domain\Matters\Models\Matter;
use App\Domain\Matters\Services\WorkflowEngine;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Opens a new matter: creates it, seeds its status history, records the
 * opposing parties, and applies the firm's workflow checklist for the case
 * type.
 */
class OpenMatter
{
    public function __construct(private readonly WorkflowEngine $workflows) {}

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>  $parties
     */
    public function execute(array $attributes, User $by, array $parties = []): Matter
    {
        return DB::transaction(function () use ($attributes, $by, $parties) {
            $matter = Matter::create([
                ...$attributes,
                'responsible_lawyer_id' => $attributes['responsible_lawyer_id'] ?? ($by->role->isLawyer() ? $by->id : null),
            ]);

            $matter->statusEvents()->create([
                'from_status' => null,
                'to_status' => $matter->status,
                'changed_by' => $by->id,
                'reason' => 'Matter opened',
            ]);

            foreach ($parties as $party) {
                $matter->parties()->create($party);
            }

            $this->workflows->applyFor($matter, $by);

            return $matter;
        });
    }
}
