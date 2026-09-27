<?php

namespace App\Support\Auth;

use App\Models\User;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * Time-based one-time passwords (RFC 6238) for staff sign-in, compatible
 * with Google Authenticator, Microsoft Authenticator, 1Password, etc.
 */
class TwoFactorAuthenticator
{
    /** Accept codes from one 30-second step either side, for clock drift. */
    private const WINDOW = 1;

    public function __construct(
        private readonly Google2FA $engine,
        private readonly Cache $cache,
    ) {}

    public function generateSecret(): string
    {
        return $this->engine->generateSecretKey(32);
    }

    /** The otpauth:// URI an authenticator app reads from the QR code. */
    public function provisioningUri(User $user, string $secret): string
    {
        return $this->engine->getQRCodeUrl(config('app.name'), $user->email, $secret);
    }

    /**
     * Check a code against the user's secret. A code is accepted at most once,
     * so an intercepted code cannot be replayed within its validity window.
     */
    public function verify(User $user, string $secret, string $code): bool
    {
        $code = preg_replace('/\s+/', '', $code);

        if (! preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        // With a non-null previous step the library returns the matched step,
        // and only accepts steps after it.
        $key = "two-factor:last-step:{$user->id}";
        $step = $this->engine->verifyKeyNewer($secret, $code, (int) $this->cache->get($key, 0), self::WINDOW);

        if (! is_int($step)) {
            return false;
        }

        $this->cache->put($key, $step, now()->addMinutes(5));

        return true;
    }

    /** @return list<string> */
    public function generateRecoveryCodes(): array
    {
        return Collection::times(8, fn () => Str::lower(Str::random(5).'-'.Str::random(5)))->all();
    }

    /** Consume a recovery code; each works exactly once. */
    public function useRecoveryCode(User $user, string $code): bool
    {
        $codes = $user->two_factor_recovery_codes ?? [];
        $code = Str::lower(trim($code));

        $match = collect($codes)->first(fn (string $candidate) => hash_equals($candidate, $code));

        if ($match === null) {
            return false;
        }

        $user->forceFill([
            'two_factor_recovery_codes' => array_values(array_filter($codes, fn ($candidate) => $candidate !== $match)),
        ])->save();

        return true;
    }
}
