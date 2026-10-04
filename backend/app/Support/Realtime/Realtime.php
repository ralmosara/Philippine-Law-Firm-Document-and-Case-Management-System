<?php

namespace App\Support\Realtime;

/**
 * How browsers reach the WebSocket server, when there is one: the same
 * origin, path /app (proxied to Reverb). Null means the screens poll.
 */
final class Realtime
{
    /** @return array{key: string}|null */
    public static function config(): ?array
    {
        if (config('broadcasting.default') !== 'reverb' || blank(config('broadcasting.connections.reverb.key'))) {
            return null;
        }

        return ['key' => (string) config('broadcasting.connections.reverb.key')];
    }
}
