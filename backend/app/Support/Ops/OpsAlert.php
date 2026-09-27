<?php

namespace App\Support\Ops;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Tells the person running the system that something needs attention.
 * The same alert (by key) goes out at most once per throttle window, so a
 * failure that repeats every minute does not flood the inbox.
 */
class OpsAlert
{
    public static function send(string $key, string $subject, string $body): bool
    {
        Log::warning("Ops alert: {$subject}", ['key' => $key]);

        $to = config('ops.alert_email');
        if (blank($to)) {
            return false;
        }

        // add() only succeeds when the key is absent: an atomic "first one wins".
        if (! Cache::add("ops:alert:{$key}", true, now()->addMinutes(config('ops.alert_throttle_minutes')))) {
            return false;
        }

        try {
            Mail::raw($body."\n\n— ".config('app.name').' ('.config('app.url').')', function ($message) use ($to, $subject) {
                $message->to($to)->subject('['.config('app.name').'] '.$subject);
            });
        } catch (Throwable $e) {
            // Alerting must never take down what it reports on.
            Log::error('Could not send ops alert', ['key' => $key, 'error' => $e->getMessage()]);

            return false;
        }

        return true;
    }
}
