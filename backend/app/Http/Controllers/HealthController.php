<?php

namespace App\Http\Controllers;

use App\Support\Ops\SystemHealth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * For uptime monitors: 200 while everything works (warnings included),
 * 503 when something is failing. Details need the HEALTH_TOKEN.
 */
class HealthController extends Controller
{
    public function __invoke(Request $request, SystemHealth $health): JsonResponse
    {
        $checks = $health->run();
        $overall = SystemHealth::overall($checks);
        $token = (string) config('ops.health_token');
        $detailed = $token !== '' && hash_equals($token, (string) $request->bearerToken());

        return response()->json([
            'status' => $overall,
            'checks' => $detailed ? $checks : array_map(fn (array $check) => $check['status'], $checks),
            'checked_at' => now()->toIso8601String(),
        ], $overall === SystemHealth::FAILING ? 503 : 200, ['Cache-Control' => 'no-store']);
    }
}
