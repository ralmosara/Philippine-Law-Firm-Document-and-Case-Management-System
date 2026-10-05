<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Matters\Enums\MatterStatus;
use App\Domain\Matters\Models\Client;
use App\Domain\Matters\Services\ClientPasswordResets;
use App\Http\Controllers\Controller;
use App\Http\Resources\ClientResource;
use App\Support\Localization\PortalLocale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class ClientController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $clients = Client::query()
            ->when($request->query('search'), fn ($q, $search) => $q->where(fn ($q) => $q
                ->whereLike('name', "%{$search}%")
                ->orWhereLike('email', "%{$search}%")
                ->orWhereLike('tin', "%{$search}%")))
            ->when($request->query('type'), fn ($q, $type) => $q->where('type', $type))
            ->withCount([
                'matters',
                'matters as active_matters_count' => fn ($q) => $q->where('status', '!=', MatterStatus::Closed->value),
            ])
            ->orderBy('name')
            ->paginate($this->perPage($request, 25));

        return ClientResource::collection($clients);
    }

    /** Lightweight id/name list for pickers. */
    public function options(): JsonResponse
    {
        return response()->json(Client::orderBy('name')->limit(2000)->get(['id', 'name', 'type']));
    }

    public function store(Request $request): JsonResponse
    {
        $client = Client::create($request->validate($this->rules()));

        return (new ClientResource($client))->response()->setStatusCode(201);
    }

    public function show(Client $client): ClientResource
    {
        $client->load(['matters' => fn ($q) => $q->with('responsibleLawyer')->latest('opened_at')]);

        return new ClientResource($client);
    }

    public function update(Request $request, Client $client): ClientResource
    {
        $client->update($request->validate($this->rules($client)));

        return new ClientResource($client);
    }

    public function destroy(Client $client): JsonResponse
    {
        Gate::authorize('manage-finances');

        if ($client->matters()->exists()) {
            abort(422, 'This client has matters on record. Close the matters instead of deleting the client.');
        }

        $client->delete();

        return response()->json(null, 204);
    }

    /**
     * Grant, revoke or reset the client's portal login.
     */
    /**
     * Turn portal access on or off. When turning it on, either set a
     * password to hand over, or (better) email the client an invitation to
     * choose their own.
     */
    public function portalAccess(Request $request, Client $client, ClientPasswordResets $resets): ClientResource
    {
        Gate::authorize('practice-law');

        $validated = $request->validate([
            'portal_enabled' => ['required', 'boolean'],
            'send_invite' => ['boolean'],
            // The portal's language for this client, and of the invitation and later emails.
            'locale' => ['sometimes', Rule::in(array_keys(PortalLocale::LOCALES))],
            'password' => [
                Rule::requiredIf(fn () => $request->boolean('portal_enabled') && ! $request->boolean('send_invite') && $client->password === null),
                'nullable', Password::defaults(),
            ],
        ]);

        if ($validated['portal_enabled'] && $client->email === null) {
            abort(422, 'Add an email address for this client before enabling portal access.');
        }

        $client->portal_enabled = $validated['portal_enabled'];
        if (isset($validated['locale'])) {
            $client->locale = $validated['locale'];
        }
        if (! empty($validated['password'])) {
            $client->password = $validated['password'];
        }
        $client->save();

        if ($client->portal_enabled && ($validated['send_invite'] ?? false) && ! $resets->sendInvite($client)) {
            abort(429, 'An invitation was sent less than a minute ago. Please wait before sending another.');
        }

        return new ClientResource($client);
    }

    private function rules(?Client $client = null): array
    {
        $required = $client ? 'sometimes' : 'required';

        return [
            'type' => [$required, Rule::in(['individual', 'corporate'])],
            'name' => [$required, 'string', 'max:255'],
            'tin' => ['nullable', 'regex:/^\d{3}-?\d{3}-?\d{3}(-?\d{3,5})?$/'],
            'email' => [
                'nullable', 'email', 'max:255',
                Rule::unique('clients', 'email')->where('firm_id', $client?->firm_id ?? request()->user()->firm_id)->ignore($client?->id),
            ],
            'phone' => ['nullable', 'string', 'max:30'],
            'hearing_reminders' => ['sometimes', 'boolean'],
            'address' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
            // Maiden or former names, trade names, affiliates: one per line.
            'aliases' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
