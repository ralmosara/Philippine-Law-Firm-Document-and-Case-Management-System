<?php

namespace App\Domain\Push;

use App\Domain\Push\Models\PushSubscription;
use App\Models\User;
use Illuminate\Notifications\Notification;
use Throwable;

/**
 * Sends a staff notification to the phones and browsers its recipient
 * turned notifications on for, as Web Push. What shows on a lock screen is,
 * by default, only the kind of notification ("A deadline was missed"):
 * client names and case details appear only for users who chose to see
 * them (Profile, push_details).
 */
class WebPushChannel
{
    /** What a notification says when details are off, by kind. */
    public const GENERIC = [
        'deadline' => 'A deadline or hearing is coming up',
        'deadline_missed' => 'Something is overdue',
        'assigned' => 'New work was assigned to you',
        'message' => 'A client sent a message',
        'intake' => 'New consultation request',
        'payment' => 'A payment was received',
        'signature' => 'A document was signed',
        'signature_declined' => 'A signature request was declined',
        'document_uploaded' => 'A client uploaded a document',
        'email' => 'An email is waiting for review',
        'disbursement' => 'Cash advance update',
        'prospect' => 'A follow-up is due',
        'tax' => 'A BIR filing is due',
        'corporate' => 'A corporate filing is due',
        'privacy' => 'A data privacy request needs attention',
    ];

    /** Kinds that are time-critical, delivered with high urgency. */
    private const URGENT = ['deadline', 'deadline_missed', 'assigned'];

    public function __construct(private readonly PushSender $sender) {}

    public function send(object $notifiable, Notification $notification): void
    {
        if (! $notifiable instanceof User || ! PushSender::enabled() || ! method_exists($notification, 'toArray')) {
            return;
        }
        $data = $notification->toArray($notifiable);
        $kind = (string) ($data['kind'] ?? 'other');

        $payload = $notifiable->push_details
            ? ['title' => (string) ($data['title'] ?? 'Lex PH'), 'body' => $data['body'] ?? null, 'url' => $data['url'] ?? '/', 'kind' => $kind]
            : ['title' => self::GENERIC[$kind] ?? 'You have a new notification', 'body' => 'Open Lex PH to see it.', 'url' => $data['url'] ?? '/', 'kind' => $kind];

        foreach (PushSubscription::where('user_id', $notifiable->id)->get() as $subscription) {
            try {
                $status = $this->sender->send($subscription, $payload, in_array($kind, self::URGENT, true));
            } catch (Throwable $e) {
                report($e);

                continue;
            }
            // Gone: the browser unsubscribed or the app was removed.
            if (in_array($status, [404, 410], true)) {
                $subscription->delete();
            } elseif ($status !== null && $status < 300) {
                $subscription->forceFill(['last_sent_at' => now()])->saveQuietly();
            }
        }
    }

    public static function wanted(User $user): bool
    {
        return PushSender::enabled() && PushSubscription::where('user_id', $user->id)->exists();
    }
}
