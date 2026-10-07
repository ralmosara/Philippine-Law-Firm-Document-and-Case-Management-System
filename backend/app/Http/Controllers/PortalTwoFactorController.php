<?php

namespace App\Http\Controllers;

use App\Domain\Matters\Models\Client;
use App\Domain\Matters\Models\Firm;
use App\Models\AuditLog;
use App\Support\Auth\TwoFactorAuthenticator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Two-step sign-in for a portal client: start (a secret to scan), confirm
 * (prove the authenticator works, get recovery codes), turn off (unless the
 * firm requires it). Staff can reset it for a client who lost their phone.
 */
class PortalTwoFactorController extends Controller
{
    public function __construct(private readonly TwoFactorAuthenticator $twoFactor) {}

    public function enable(Request $request): JsonResponse
    {
        $client = $this->checkPassword($request);
        if ($client->hasTwoFactorEnabled()) {
            throw ValidationException::withMessages(['password' => __('Two-step sign-in is already on.')]);
        }

        $secret = $this->twoFactor->generateSecret();
        $client->forceFill(['two_factor_secret' => $secret, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->save();

        return response()->json(['secret' => $secret, 'otpauth_url' => $this->twoFactor->provisioningUri($client, $secret)]);
    }

    public function confirm(Request $request): JsonResponse
    {
        $validated = $request->validate(['code' => ['required', 'string', 'max:16']]);
        $client = $request->user('client');
        if ($client->two_factor_secret === null || $client->hasTwoFactorEnabled()) {
            throw ValidationException::withMessages(['code' => __('Start the setup first.')]);
        }
        if (! $this->twoFactor->verify($client, $client->two_factor_secret, $validated['code'])) {
            throw ValidationException::withMessages(['code' => __('That code is not valid. Check the time on your phone and try again.')]);
        }

        $codes = $this->twoFactor->generateRecoveryCodes();
        $client->forceFill(['two_factor_confirmed_at' => now(), 'two_factor_recovery_codes' => $codes])->save();
        AuditLog::record('portal_two_factor_enabled', $client->firm_id, $client, $client);

        return response()->json(['recovery_codes' => $codes]);
    }

    public function disable(Request $request): JsonResponse
    {
        $client = $this->checkPassword($request);
        if (Firm::whereKey($client->firm_id)->value('portal_two_factor') === 'required') {
            throw ValidationException::withMessages(['password' => __('Your lawyers require two-step sign-in, so it cannot be turned off.')]);
        }
        $this->clear($client);
        AuditLog::record('portal_two_factor_disabled', $client->firm_id, $client, $client);

        return response()->json(['message' => __('Two-step sign-in is off.')]);
    }

    public function regenerateRecoveryCodes(Request $request): JsonResponse
    {
        $client = $this->checkPassword($request);
        if (! $client->hasTwoFactorEnabled()) {
            throw ValidationException::withMessages(['password' => __('Two-step sign-in is not on.')]);
        }
        $codes = $this->twoFactor->generateRecoveryCodes();
        $client->forceFill(['two_factor_recovery_codes' => $codes])->save();

        return response()->json(['recovery_codes' => $codes]);
    }

    /** Staff reset a client who lost their authenticator (after confirming who they are). */
    public function reset(Request $request, Client $client): JsonResponse
    {
        Gate::authorize('practice-law');
        $this->clear($client);
        AuditLog::record('portal_two_factor_reset', $client->firm_id, $request->user(), $client);

        return response()->json(['message' => "Two-step sign-in has been reset for {$client->name}. They set it up again at their next sign-in if the firm requires it."]);
    }

    private function checkPassword(Request $request): Client
    {
        $request->validate(['password' => ['required', 'string']]);
        $client = $request->user('client');
        if (! Hash::check($request->input('password'), (string) $client->password)) {
            throw ValidationException::withMessages(['password' => __('The password is incorrect.')]);
        }

        return $client;
    }

    private function clear(Client $client): void
    {
        $client->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->save();
    }
}
