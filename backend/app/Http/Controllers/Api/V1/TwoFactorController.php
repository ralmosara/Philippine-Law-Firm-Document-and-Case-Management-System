<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Matters\Models\Firm;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\Auth\TwoFactorAuthenticator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Two-factor authentication settings for the signed-in staff member.
 *
 * Enabling is two steps: start (a new secret to scan) then confirm (prove
 * the authenticator works). Until confirmed, sign-in is unaffected.
 */
class TwoFactorController extends Controller
{
    public function __construct(private readonly TwoFactorAuthenticator $twoFactor) {}

    public function enable(Request $request): JsonResponse
    {
        $request->validate(['password' => ['required', 'current_password:web']]);
        $user = $request->user();

        if ($user->hasTwoFactorEnabled()) {
            throw ValidationException::withMessages(['password' => 'Two-factor authentication is already on.']);
        }

        $secret = $this->twoFactor->generateSecret();
        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        return response()->json([
            'secret' => $secret,
            'otpauth_url' => $this->twoFactor->provisioningUri($user, $secret),
        ]);
    }

    public function confirm(Request $request): JsonResponse
    {
        $validated = $request->validate(['code' => ['required', 'string', 'max:16']]);
        $user = $request->user();

        if ($user->two_factor_secret === null || $user->hasTwoFactorEnabled()) {
            abort(422, 'Start two-factor setup first.');
        }

        if (! $this->twoFactor->verify($user, $user->two_factor_secret, $validated['code'])) {
            throw ValidationException::withMessages(['code' => 'That code is not valid. Check the time on your phone and try again.']);
        }

        $codes = $this->twoFactor->generateRecoveryCodes();
        $user->forceFill(['two_factor_confirmed_at' => now(), 'two_factor_recovery_codes' => $codes])->save();
        AuditLog::record('two_factor_enabled', $user->firm_id, $user, $user);

        return response()->json(['recovery_codes' => $codes]);
    }

    public function disable(Request $request): JsonResponse
    {
        $request->validate(['password' => ['required', 'current_password:web']]);

        if (Firm::whereKey($request->user()->firm_id)->value('require_two_factor')) {
            throw ValidationException::withMessages(['password' => 'Your firm requires two-step verification, so it cannot be turned off.']);
        }

        $this->clear($request->user(), $request->user());

        return response()->json(['message' => 'Two-factor authentication is off.']);
    }

    public function regenerateRecoveryCodes(Request $request): JsonResponse
    {
        $request->validate(['password' => ['required', 'current_password:web']]);
        $user = $request->user();

        if (! $user->hasTwoFactorEnabled()) {
            abort(422, 'Two-factor authentication is not on.');
        }

        $codes = $this->twoFactor->generateRecoveryCodes();
        $user->forceFill(['two_factor_recovery_codes' => $codes])->save();

        return response()->json(['recovery_codes' => $codes]);
    }

    /** A firm administrator resets a colleague who has lost their authenticator. */
    public function reset(Request $request, User $user): JsonResponse
    {
        Gate::authorize('manage-firm');

        $this->clear($user, $request->user());

        return response()->json(['message' => "Two-factor authentication has been reset for {$user->name}."]);
    }

    private function clear(User $user, User $by): void
    {
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        AuditLog::record('two_factor_disabled', $user->firm_id, $by, $user);
    }
}
