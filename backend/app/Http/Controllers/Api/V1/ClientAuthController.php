<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Matters\Models\Client;
use App\Domain\Matters\Services\ClientPasswordResets;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Support\Localization\PortalLocale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Client portal authentication on the dedicated `client` session guard.
 */
class ClientAuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        // An email is unique within a firm, but the same person may be a
        // client of more than one firm; the password identifies which.
        $client = Client::withoutGlobalScopes()
            ->where('email', $credentials['email'])
            ->where('portal_enabled', true)
            ->whereNotNull('password')
            ->get()
            ->first(fn (Client $candidate) => Hash::check($credentials['password'], $candidate->password));

        if ($client === null) {
            throw ValidationException::withMessages(['email' => __('These credentials do not match our records.')]);
        }

        Auth::guard('client')->login($client);

        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        $client->forceFill(['last_portal_login_at' => now()])->saveQuietly();
        AuditLog::record('portal_login', $client->firm_id, $client, $client);

        return response()->json(['client' => $this->profile($client)]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['client' => $this->profile($request->user('client'))]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('client')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['message' => __('Signed out.')]);
    }

    /** The portal's language for this client, also used for the firm's emails to them. */
    public function locale(Request $request): JsonResponse
    {
        $validated = $request->validate(['locale' => ['required', Rule::in(array_keys(PortalLocale::LOCALES))]]);
        $client = $request->user('client');
        $client->forceFill(['locale' => $validated['locale']])->save();
        app()->setLocale($validated['locale']);

        return response()->json(['client' => $this->profile($client)]);
    }

    /**
     * Email reset links. The same response whether or not an account
     * exists, so the endpoint cannot be used to find clients.
     */
    public function forgotPassword(Request $request, ClientPasswordResets $resets): JsonResponse
    {
        $validated = $request->validate(['email' => ['required', 'email']]);

        $resets->sendResetLinks($validated['email']);

        return response()->json([
            'message' => __('If a portal account exists for that email, a password reset link is on its way.'),
        ]);
    }

    public function resetPassword(Request $request, ClientPasswordResets $resets): JsonResponse
    {
        $validated = $request->validate([
            'client' => ['required', 'integer'],
            'token' => ['required', 'string', 'max:128'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        if (! $resets->reset($validated['client'], $validated['token'], $validated['password'])) {
            throw ValidationException::withMessages(['token' => __('This link is invalid or has expired. Please request a new one.')]);
        }

        return response()->json(['message' => __('Your password is set. You can now sign in.')]);
    }

    private function profile(Client $client): array
    {
        return [
            'id' => $client->id,
            'name' => $client->name,
            'email' => $client->email,
            'locale' => PortalLocale::normalize($client->locale),
            'firm' => $client->firm()->withoutGlobalScopes()->first(['id', 'name', 'email', 'phone', 'address']),
        ];
    }
}
