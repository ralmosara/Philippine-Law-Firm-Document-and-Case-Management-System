<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('manage-firm');

        $logs = AuditLog::query()
            ->with('actor')
            ->when($request->query('action'), fn ($q, $action) => $q->where('action', $action))
            ->when($request->query('subject_type'), fn ($q, $type) => $q->where('subject_type', $type))
            ->when($request->query('subject_id'), fn ($q, $id) => $q->where('subject_id', $id))
            ->latest('id')
            ->paginate($this->perPage($request, 50))
            ->through(fn (AuditLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'actor' => $log->actor ? ['type' => $log->actor_type, 'id' => $log->actor->getKey(), 'name' => $log->actor->name] : null,
                'subject_type' => $log->subject_type,
                'subject_id' => $log->subject_id,
                'changes' => $log->changes,
                'ip_address' => $log->ip_address,
                'created_at' => $log->created_at?->toIso8601String(),
            ]);

        return JsonResource::collection($logs)->response();
    }
}
