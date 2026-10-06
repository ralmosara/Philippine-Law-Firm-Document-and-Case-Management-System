<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Matters\Models\Client;
use App\Domain\Matters\Models\Firm;
use App\Domain\Matters\Services\ClientPasswordResets;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Support\Auth\TwoFactorAuthenticator;
use App\Support\Localization\PortalLocale;
use App\Support\Realtime\Realtime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Client portal authentication on the dedicated `client` session guard.
 */
class ClientAuthController extends Controller
{
    /** How long a password-verified sign-in waits for its authenticator code. */
    private const CHALLENGE_TTL_SECONDS = 300;

    /** Wrong codes allowed per challenge before the password must be re-entered. */
    private const CHALLENGE_MAX_ATTEMPTS = 5;

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

        if ($client->hasTwoFactorEnabled()) {
            // The password is right; the authenticator code must accompany this short-lived challenge.
            return response()->json([
                'two_factor' => true,
                'challenge' => Crypt::encryptString(json_encode([
                    'client_id' => $client->id,
                    'expires_at' => now()->addSeconds(self::CHALLENGE_TTL_SECONDS)->timestamp,
                    'nonce' => Str::random(32),
                ])),
            ]);
        }

        return $this->completeLogin($request, $client);
    }

    /** Second step of a two-step portal sign-in: an authenticator or recovery code. */
    public function twoFactorChallenge(Request $request, TwoFactorAuthenticator $twoFactor): JsonResponse
    {
        $validated = $request->validate([
            'challenge' => ['required', 'string'],
            'code' => ['required_without:recovery_code', 'nullable', 'string', 'max:16'],
            'recovery_code' => ['required_without:code', 'nullable', 'string', 'max:32'],
        ]);

        try {
            $challenge = json_decode(Crypt::decryptString($validated['challenge']), true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['code' => __('Please sign in again.')]);
        }
        if (($challenge['expires_at'] ?? 0) < now()->timestamp) {
            throw ValidationException::withMessages(['code' => __('Please sign in again.')]);
        }
        $attemptsKey = "portal-two-factor:challenge:{$challenge['nonce']}";
        if (Cache::get($attemptsKey, 0) >= self::CHALLENGE_MAX_ATTEMPTS) {
            throw ValidationException::withMessages(['code' => __('Too many incorrect codes. Please sign in again.')]);
        }

        $client = Client::withoutGlobalScopes()->find($challenge['client_id'] ?? 0);
        if ($client === null || ! $client->portal_enabled || ! $client->hasTwoFactorEnabled()) {
            throw ValidationException::withMessages(['code' => __('Please sign in again.')]);
        }

        $valid = filled($validated['code'] ?? null)
            ? $twoFactor->verify($client, $client->two_factor_secret, $validated['code'])
            : $twoFactor->useRecoveryCode($client, $validated['recovery_code']);
        if (! $valid) {
            Cache::add($attemptsKey, 0, self::CHALLENGE_TTL_SECONDS);
            Cache::increment($attemptsKey);

            throw ValidationException::withMessages([filled($validated['code'] ?? null) ? 'code' : 'recovery_code' => __('That code is not valid.')]);
        }

        // A challenge completes a sign-in once.
        Cache::put($attemptsKey, self::CHALLENGE_MAX_ATTEMPTS, self::CHALLENGE_TTL_SECONDS);
        if (filled($validated['recovery_code'] ?? null)) {
            AuditLog::record('portal_two_factor_recovery_code_used', $client->firm_id, $client, $client);
        }

        return $this->completeLogin($request, $client);
    }

    private function completeLogin(Request $request, Client $client): JsonResponse
    {
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
            'two_factor_enabled' => $client->hasTwoFactorEnabled(),
            'two_factor_required' => Firm::whereKey($client->firm_id)->value('portal_two_factor') === 'required',
            // For live message threads; null means the portal polls.
            'realtime' => Realtime::config(),
            'firm' => $client->firm()->withoutGlobalScopes()->first(['id', 'name', 'email', 'phone', 'address']),
        ];
    }
}
