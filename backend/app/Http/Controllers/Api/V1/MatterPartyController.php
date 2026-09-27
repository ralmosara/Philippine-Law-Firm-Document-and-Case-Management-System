<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Matters\Enums\PartyRole;
use App\Domain\Matters\Models\Matter;
use App\Domain\Matters\Models\MatterParty;
use App\Http\Controllers\Controller;
use App\Http\Resources\MatterPartyResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rules\Enum;

/**
 * Parties are routed as matters/{matter}/parties/{party} with scoped
 * bindings, so a party is only reachable through its own (tenant-scoped)
 * matter.
 */
class MatterPartyController extends Controller
{
    public function store(Request $request, Matter $matter): JsonResponse
    {
        Gate::authorize('work-matters');

        $party = $matter->parties()->create($request->validate($this->rules()));

        return (new MatterPartyResource($party))->response()->setStatusCode(201);
    }

    public function update(Request $request, Matter $matter, MatterParty $party): MatterPartyResource
    {
        Gate::authorize('work-matters');

        $party->update($request->validate(array_map(fn (array $rules) => ['sometimes', ...$rules], $this->rules())));

        return new MatterPartyResource($party);
    }

    public function destroy(Matter $matter, MatterParty $party): JsonResponse
    {
        Gate::authorize('work-matters');

        $party->delete();

        return response()->json(null, 204);
    }

    private function rules(): array
    {
        return [
            'role' => ['required', new Enum(PartyRole::class)],
            'name' => ['required', 'string', 'max:255'],
            'counsel_name' => ['nullable', 'string', 'max:255'],
            'contact' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
