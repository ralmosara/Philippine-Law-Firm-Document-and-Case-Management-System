<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\Auth\TwoFactorAuthenticator;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Staff authentication for the SPA, using Sanctum's cookie-based sessions.
 * The SPA first calls GET /sanctum/csrf-cookie, then POSTs credentials here.
 */
class AuthController extends Controller
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
            'remember' => ['boolean'],
        ]);

        $user = User::withoutGlobalScopes()->where('email', $credentials['email'])->first();

        // Same message for unknown email, wrong password and inactive account,
        // so the endpoint cannot be used to enumerate accounts.
        if ($user === null || ! $user->is_active || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => 'These credentials do not match our records.']);
        }

        if ($user->hasTwoFactorEnabled()) {
            // The password is right; hand back a short-lived, encrypted
            // challenge that the authenticator code must accompany.
            return response()->json([
                'two_factor' => true,
                'challenge' => Crypt::encryptString(json_encode([
                    'user_id' => $user->id,
                    'remember' => $credentials['remember'] ?? false,
                    'expires_at' => now()->addSeconds(self::CHALLENGE_TTL_SECONDS)->timestamp,
                    'nonce' => Str::random(32),
                ])),
            ]);
        }

        return $this->completeLogin($request, $user, $credentials['remember'] ?? false);
    }

    /** Second step of a two-factor sign-in: an authenticator or recovery code. */
    public function twoFactorChallenge(Request $request, TwoFactorAuthenticator $twoFactor): JsonResponse
    {
        $validated = $request->validate([
            'challenge' => ['required', 'string'],
            'code' => ['required_without:recovery_code', 'nullable', 'string', 'max:16'],
            'recovery_code' => ['required_without:code', 'nullable', 'string', 'max:32'],
        ]);

        $challenge = $this->readChallenge($validated['challenge']);
        $attemptsKey = "two-factor:challenge:{$challenge['nonce']}";

        if (Cache::get($attemptsKey, 0) >= self::CHALLENGE_MAX_ATTEMPTS) {
            throw ValidationException::withMessages(['code' => 'Too many incorrect codes. Please sign in again.']);
        }

        $user = User::withoutGlobalScopes()->find($challenge['user_id']);

        if ($user === null || ! $user->is_active || ! $user->hasTwoFactorEnabled()) {
            throw ValidationException::withMessages(['code' => 'Please sign in again.']);
        }

        $valid = filled($validated['code'] ?? null)
            ? $twoFactor->verify($user, $user->two_factor_secret, $validated['code'])
            : $twoFactor->useRecoveryCode($user, $validated['recovery_code']);

        if (! $valid) {
            Cache::add($attemptsKey, 0, self::CHALLENGE_TTL_SECONDS);
            Cache::increment($attemptsKey);

            throw ValidationException::withMessages([
                filled($validated['code'] ?? null) ? 'code' : 'recovery_code' => 'That code is not valid.',
            ]);
        }

        // A challenge completes a sign-in once.
        Cache::put($attemptsKey, self::CHALLENGE_MAX_ATTEMPTS, self::CHALLENGE_TTL_SECONDS);

        if (filled($validated['recovery_code'] ?? null)) {
            AuditLog::record('two_factor_recovery_code_used', $user->firm_id, $user, $user);
        }

        return $this->completeLogin($request, $user, (bool) $challenge['remember']);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'user' => new UserResource($user),
            'firm' => $user->firm()->first(['id', 'name', 'tin', 'address', 'email', 'phone', 'vat_registered', 'require_two_factor', 'default_withholding_bps']),
            'abilities' => [
                'manage_firm' => $user->role->canManageFirm(),
                'manage_finances' => $user->role->canManageFinances(),
                'work_matters' => $user->role->canWorkMatters(),
                'practice_law' => $user->role->isLawyer(),
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['message' => 'Signed out.']);
    }

    public function updatePassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'current_password:web'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $request->user()->forceFill(['password' => $validated['password']])->save();

        return response()->json(['message' => 'Password updated.']);
    }

    /**
     * A lawyer keeps their own credentials current: PTR and IBP numbers are
     * renewed every year, and pleadings print them under the signature.
     */
    public function updateCredentials(Request $request): JsonResponse
    {
        $request->user()->update($request->validate([
            'roll_number' => ['nullable', 'string', 'max:32'],
            'ibp_number' => ['nullable', 'string', 'max:32'],
            'ptr_number' => ['nullable', 'string', 'max:100'],
            'mcle_compliance_number' => ['nullable', 'string', 'max:100'],
        ]));

        return response()->json(['user' => new UserResource($request->user()->fresh())]);
    }

    /**
     * Email a password reset link. The response is the same whether or not
     * the account exists, so the endpoint cannot be used to find accounts.
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $validated = $request->validate(['email' => ['required', 'email']]);

        $user = User::withoutGlobalScopes()->where('email', $validated['email'])->first();

        if ($user?->is_active) {
            PasswordBroker::broker('users')->sendResetLink(['email' => $user->email]);
        }

        return response()->json([
            'message' => 'If an account exists for that email, a password reset link is on its way.',
        ]);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $status = PasswordBroker::broker('users')->reset(
            [...$validated, 'password_confirmation' => $request->input('password_confirmation')],
            function (User $user, string $password) {
                $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();

                // Sign out every existing session for this account.
                if (config('session.driver') === 'database') {
                    DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
                }

                AuditLog::record('password_reset', $user->firm_id, $user, $user);
                event(new PasswordReset($user));
            },
        );

        if ($status !== PasswordBroker::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => 'This password reset link is invalid or has expired.']);
        }

        return response()->json(['message' => 'Your password has been reset. You can now sign in.']);
    }

    private function completeLogin(Request $request, User $user, bool $remember): JsonResponse
    {
        Auth::guard('web')->login($user, $remember);

        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        $user->forceFill(['last_login_at' => now()])->saveQuietly();
        AuditLog::record('login', $user->firm_id, $user, $user);

        return response()->json(['user' => new UserResource($user)]);
    }

    /** @return array{user_id: int, remember: bool, expires_at: int, nonce: string} */
    private function readChallenge(string $token): array
    {
        try {
            $challenge = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            $challenge = null;
        }

        if (! is_array($challenge) || ($challenge['expires_at'] ?? 0) < now()->timestamp) {
            throw ValidationException::withMessages(['code' => 'This sign-in has expired. Please sign in again.']);
        }

        return $challenge;
    }
}
