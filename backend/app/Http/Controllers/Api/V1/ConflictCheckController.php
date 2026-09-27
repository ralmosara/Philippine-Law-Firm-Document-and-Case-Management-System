<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Compliance\Enums\ConflictCheckStatus;
use App\Domain\Compliance\Models\ConflictCheck;
use App\Domain\Compliance\Services\ConflictChecker;
use App\Http\Controllers\Controller;
use App\Http\Resources\ConflictCheckResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ConflictCheckController extends Controller
{
    public function __construct(private readonly ConflictChecker $checker) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return ConflictCheckResource::collection(
            ConflictCheck::query()
                ->with(['requester', 'resolver'])
                ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
                ->latest('id')
                ->paginate($this->perPage($request, 25))
        );
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate(['name' => ['required', 'string', 'min:2', 'max:255']]);

        $check = $this->checker->check($request->user()->firm_id, $validated['name'], $request->user());

        return (new ConflictCheckResource($check->load(['requester', 'resolver'])))->response()->setStatusCode(201);
    }

    public function show(ConflictCheck $conflictCheck): ConflictCheckResource
    {
        return new ConflictCheckResource($conflictCheck->load(['requester', 'resolver']));
    }

    public function resolve(Request $request, ConflictCheck $conflictCheck): ConflictCheckResource
    {
        Gate::authorize('practice-law');

        $validated = $request->validate([
            'status' => ['required', Rule::in([ConflictCheckStatus::Waived->value, ConflictCheckStatus::Declined->value])],
            'notes' => ['required', 'string', 'max:5000'],
        ]);

        $this->checker->resolve($conflictCheck, ConflictCheckStatus::from($validated['status']), $validated['notes'], $request->user());

        return new ConflictCheckResource($conflictCheck->load(['requester', 'resolver']));
    }
}
