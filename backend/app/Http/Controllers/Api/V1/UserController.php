<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\Password;

class UserController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $users = User::query()
            ->when($request->query('search'), fn ($q, $search) => $q->where(fn ($q) => $q
                ->whereLike('name', "%{$search}%")
                ->orWhereLike('email', "%{$search}%")))
            ->when($request->boolean('lawyers_only'), fn ($q) => $q->whereIn('role', array_map(
                fn (Role $role) => $role->value,
                array_filter(Role::cases(), fn (Role $role) => $role->isLawyer()),
            )))
            ->when($request->boolean('active_only'), fn ($q) => $q->where('is_active', true))
            ->orderBy('name')
            ->paginate($this->perPage($request, 25));

        return UserResource::collection($users);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('manage-firm');

        $validated = $request->validate([
            ...$this->rules(),
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', Password::defaults()],
        ]);

        $user = User::create($validated);

        return (new UserResource($user))->response()->setStatusCode(201);
    }

    public function show(User $user): UserResource
    {
        return new UserResource($user);
    }

    public function update(Request $request, User $user): UserResource
    {
        Gate::authorize('manage-firm');

        $validated = $request->validate([
            ...array_map(fn (array $rules) => ['sometimes', ...$rules], $this->rules()),
            'email' => ['sometimes', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['sometimes', Password::defaults()],
        ]);

        if ($user->is($request->user()) && (($validated['is_active'] ?? true) === false || ($validated['role'] ?? $user->role->value) !== $user->role->value)) {
            abort(422, 'You cannot deactivate yourself or change your own role.');
        }

        $user->update($validated);

        return new UserResource($user);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        Gate::authorize('delete-user', $user);

        // Users are deactivated rather than deleted: their names remain on
        // time entries, status history and the notarial register.
        $user->update(['is_active' => false]);

        return response()->json(null, 204);
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'role' => ['required', new Enum(Role::class)],
            'ibp_number' => ['nullable', 'string', 'max:32'],
            'roll_number' => ['nullable', 'string', 'max:32'],
            'ptr_number' => ['nullable', 'string', 'max:100'],
            'mcle_compliance_number' => ['nullable', 'string', 'max:100'],
            'mobile_number' => ['nullable', 'regex:/^(\+63|0)9\d{9}$/'],
            'hourly_rate_cents' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'is_active' => ['boolean'],
        ];
    }
}
