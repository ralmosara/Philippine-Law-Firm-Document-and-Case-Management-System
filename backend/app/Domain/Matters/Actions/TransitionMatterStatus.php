<?php

namespace App\Domain\Matters\Actions;

use App\Domain\Feedback\ClientFeedback;
use App\Domain\Matters\Enums\MatterStatus;
use App\Domain\Matters\Models\Matter;
use App\Domain\Matters\Services\MatterClosing;
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
    /**
     * $askFeedback: on closing, ask a portal client how the firm did (once per matter).
     * $acknowledged: on closing, the person has seen the warnings (unbilled work, unpaid bills, open deadlines).
     */
    public function execute(Matter $matter, MatterStatus $to, User $by, ?string $reason = null, bool $askFeedback = true, bool $acknowledged = false): Matter
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

        if ($to === MatterStatus::Closed) {
            $checks = app(MatterClosing::class)->check($matter);
            if ($checks['blockers'] !== []) {
                throw ValidationException::withMessages(['closing' => array_column($checks['blockers'], 'message')]);
            }
            if ($checks['warnings'] !== [] && ! $acknowledged) {
                throw ValidationException::withMessages(['closing' => ['Review what is still open before closing: '.implode(' ', array_column($checks['warnings'], 'message'))]]);
            }
        }

        return DB::transaction(function () use ($matter, $from, $to, $by, $reason, $askFeedback) {
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

            if ($to === MatterStatus::Closed) {
                app(MatterClosing::class)->afterClosed($matter, $by);
            }

            if ($to === MatterStatus::Closed && $askFeedback) {
                app(ClientFeedback::class)->requestFor($matter);
            }

            return $matter;
        });
    }
}
