<?php

namespace App\Domain\Matters\Actions;

use App\Domain\Matters\Enums\MatterStatus;
use App\Domain\Matters\Models\Matter;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The only way a matter's status changes. Enforces the state machine in
 * MatterStatus and appends the change to the matter's status history in the
 * same transaction.
 */
class TransitionMatterStatus
{
    public function execute(Matter $matter, MatterStatus $to, User $by, ?string $reason = null): Matter
    {
        $from = $matter->status;

        if (! $from->canTransitionTo($to)) {
            throw ValidationException::withMessages([
                'status' => sprintf(
                    'A matter cannot move from %s to %s. Allowed: %s.',
                    $from->label(),
                    $to->label(),
                    collect($from->allowedTransitions())->map->label()->join(', '),
                ),
            ]);
        }

        if ($to === MatterStatus::Closed && blank($reason)) {
            throw ValidationException::withMessages(['reason' => 'A reason is required to close a matter.']);
        }

        return DB::transaction(function () use ($matter, $from, $to, $by, $reason) {
            $matter->forceFill([
                'status' => $to,
                'closed_at' => $to === MatterStatus::Closed ? now() : null,
            ])->save();

            $matter->statusEvents()->create([
                'from_status' => $from,
                'to_status' => $to,
                'changed_by' => $by->id,
                'reason' => $reason,
            ]);

            return $matter;
        });
    }
}
