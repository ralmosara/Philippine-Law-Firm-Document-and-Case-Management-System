<?php

namespace App\Domain\Feedback;

use App\Domain\Feedback\Notifications\FeedbackRequested;
use App\Domain\Feedback\Notifications\LowRatingReceived;
use App\Domain\Matters\Models\Matter;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Feedback from clients on closed matters. Asked once per matter, only of
 * clients with portal access (they answer there, in their language). A
 * poor rating (2 or less) goes to the responsible lawyer and the managing
 * partners, who record how they followed up.
 */
class ClientFeedback
{
    /** On closing a matter. */
    public function requestFor(Matter $matter): ?MatterFeedback
    {
        $matter->loadMissing('client');
        $client = $matter->client;
        if ($client === null || ! $client->portal_enabled || blank($client->email)) {
            return null;
        }
        // Closed, reopened and closed again: asked the first time only.
        if (MatterFeedback::query()->where('matter_id', $matter->id)->exists()) {
            return null;
        }

        $feedback = MatterFeedback::create(['firm_id' => $matter->firm_id, 'matter_id' => $matter->id, 'client_id' => $client->id, 'requested_at' => now()]);
        DB::afterCommit(fn () => $client->notify(new FeedbackRequested($feedback->setRelation('matter', $matter))));

        return $feedback;
    }

    /** The client's answer, from the portal; it can be changed until the firm has followed up. */
    public function respond(MatterFeedback $feedback, int $rating, ?string $comment): MatterFeedback
    {
        if ($feedback->followed_up_at !== null) {
            throw ValidationException::withMessages(['rating' => __('Thank you; the firm has already followed up on your feedback.')]);
        }

        $wasLow = $feedback->isLow();
        $feedback->forceFill(['rating' => $rating, 'comment' => filled($comment) ? trim($comment) : null, 'responded_at' => now()])->save();

        if ($feedback->isLow() && ! $wasLow) {
            $feedback->loadMissing('matter:id,firm_id,reference,title,responsible_lawyer_id', 'client:id,name');
            $recipients = User::query()
                ->where('firm_id', $feedback->firm_id)
                ->where('is_active', true)
                ->where(fn ($q) => $q->where('id', $feedback->matter->responsible_lawyer_id)->orWhere('role', Role::ManagingPartner->value))
                ->get();
            Notification::send($recipients, new LowRatingReceived($feedback));
        }

        return $feedback;
    }

    public function followUp(MatterFeedback $feedback, string $note, User $by): MatterFeedback
    {
        $feedback->forceFill(['followed_up_at' => now(), 'followed_up_by' => $by->id, 'follow_up_note' => $note])->save();

        return $feedback;
    }
}
