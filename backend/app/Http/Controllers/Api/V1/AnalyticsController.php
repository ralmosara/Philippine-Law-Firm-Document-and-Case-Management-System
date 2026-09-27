<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Analytics\AnalyticsService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class AnalyticsController extends Controller
{
    public function dashboard(AnalyticsService $analytics): JsonResponse
    {
        Gate::authorize('manage-finances');

        return response()->json($analytics->dashboard());
    }
}
