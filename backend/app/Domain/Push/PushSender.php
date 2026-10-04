<?php

namespace App\Domain\Push;

use App\Domain\Push\Models\PushSubscription;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/**
 * Delivers one encrypted Web Push message (RFC 8291, VAPID). Separate from
 * the channel so tests can replace it.
 */
class PushSender
{
    /** Push services a browser may register; anything else is refused (the server would POST to it). */
    public const ALLOWED_HOSTS = [
        'fcm.googleapis.com', 'android.googleapis.com',          // Chrome, Edge on Android, Samsung
        'updates.push.services.mozilla.com',                   // Firefox
        'web.push.apple.com',                                  // Safari, iOS home-screen apps
        '*.notify.windows.com',                                // Edge on Windows
        '*.push.apple.com',
    ];

    public static function enabled(): bool
    {
        return filled(config('services.webpush.public_key')) && filled(config('services.webpush.private_key'));
    }

    public static function allowedEndpoint(string $endpoint): bool
    {
        $parts = parse_url($endpoint);
        if (($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['port'])) {
            return false;
        }
        $host = strtolower($parts['host']);
        foreach (self::ALLOWED_HOSTS as $allowed) {
            if ($host === $allowed || (str_starts_with($allowed, '*.') && str_ends_with($host, substr($allowed, 1)))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return int|null the push service's HTTP status, or null when it could not be reached
     */
    public function send(PushSubscription $subscription, array $payload, bool $urgent = false): ?int
    {
        $push = new WebPush([
            'VAPID' => [
                'subject' => config('services.webpush.subject') ?: rtrim((string) config('app.url'), '/'),
                'publicKey' => config('services.webpush.public_key'),
                'privateKey' => config('services.webpush.private_key'),
            ],
        ], ['TTL' => 86400, 'urgency' => $urgent ? 'high' : 'normal'], 10);

        $report = $push->sendOneNotification(
            Subscription::create(['endpoint' => $subscription->endpoint, 'keys' => ['p256dh' => $subscription->p256dh, 'auth' => $subscription->auth]]),
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );

        return $report->getResponse()?->getStatusCode() ?? ($report->isSuccess() ? 201 : null);
    }
}
