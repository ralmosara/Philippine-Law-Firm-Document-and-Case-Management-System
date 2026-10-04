<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Makes a write safe to repeat. A phone that saved something while offline
 * sends it again when the signal returns, and may send it twice if the
 * first answer was lost on the way back. With an Idempotency-Key header,
 * the first request is processed and its answer kept for a day; any
 * repeat with the same key gets that answer instead of a second entry.
 */
class Idempotent
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = (string) $request->header('Idempotency-Key');
        if ($key === '' || ! preg_match('/^[A-Za-z0-9-]{16,64}$/', $key) || ! $request->user()) {
            return $next($request);
        }

        $cacheKey = 'idempotency:'.$request->user()->getAuthIdentifier().':'.$request->method().':'.$request->path().':'.$key;

        return Cache::lock($cacheKey.':lock', 30)->block(10, function () use ($cacheKey, $request, $next) {
            if ($stored = Cache::get($cacheKey)) {
                return response($stored['body'], $stored['status'], ['Content-Type' => $stored['type'], 'Idempotent-Replayed' => 'true']);
            }

            $response = $next($request);

            // Errors on our side are not remembered: a retry should try again.
            if ($response->getStatusCode() < 500) {
                Cache::put($cacheKey, [
                    'status' => $response->getStatusCode(),
                    'body' => $response->getContent(),
                    'type' => $response->headers->get('Content-Type', 'application/json'),
                ], now()->addDay());
            }

            return $response;
        });
    }
}
