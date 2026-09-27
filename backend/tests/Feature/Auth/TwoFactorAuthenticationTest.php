<?php

namespace Tests\Feature\Auth;

use App\Domain\Matters\Models\Firm;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class TwoFactorAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_enabling_requires_the_password_and_a_working_code(): void
    {
        $user = $this->signIn(Role::Associate);

        $this->postJson('/api/v1/auth/two-factor', ['password' => 'wrong'])->assertStatus(422);

        $secret = $this->postJson('/api/v1/auth/two-factor', ['password' => 'password'])
            ->assertOk()
            ->assertJsonStructure(['secret', 'otpauth_url'])
            ->json('secret');

        // Not on until confirmed.
        $this->assertFalse($user->fresh()->hasTwoFactorEnabled());

        $this->postJson('/api/v1/auth/two-factor/confirm', ['code' => '000000'])->assertStatus(422);

        $codes = $this->postJson('/api/v1/auth/two-factor/confirm', ['code' => $this->otp($secret)])
            ->assertOk()
            ->assertJsonCount(8, 'recovery_codes')
            ->json('recovery_codes');

        $user->refresh();
        $this->assertTrue($user->hasTwoFactorEnabled());
        $this->assertSame($codes, $user->two_factor_recovery_codes);
        // Stored encrypted, never in plain text.
        $this->assertNotSame($secret, $user->getRawOriginal('two_factor_secret'));
        $this->getJson('/api/v1/auth/me')->assertJsonPath('user.two_factor_enabled', true);
    }

    public function test_sign_in_asks_for_a_code_and_accepts_each_code_once(): void
    {
        [$user, $secret] = $this->userWithTwoFactor();

        $challenge = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('two_factor', true)
            ->assertJsonMissingPath('user')
            ->json('challenge');
        $this->assertGuest();

        $this->postJson('/api/v1/auth/two-factor-challenge', ['challenge' => $challenge, 'code' => '123456'])
            ->assertStatus(422)->assertJsonValidationErrors('code');
        $this->assertGuest();

        $code = $this->otp($secret);
        $this->postJson('/api/v1/auth/two-factor-challenge', ['challenge' => $challenge, 'code' => $code])
            ->assertOk()->assertJsonPath('user.id', $user->id);
        $this->assertAuthenticatedAs($user);

        // Replaying the same code on a fresh sign-in fails.
        $this->postJson('/api/v1/auth/logout');
        $again = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])->json('challenge');
        $this->postJson('/api/v1/auth/two-factor-challenge', ['challenge' => $again, 'code' => $code])->assertStatus(422);
    }

    public function test_a_recovery_code_works_exactly_once(): void
    {
        [$user] = $this->userWithTwoFactor();
        $recovery = $user->two_factor_recovery_codes[0];

        $challenge = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])->json('challenge');
        $this->postJson('/api/v1/auth/two-factor-challenge', ['challenge' => $challenge, 'recovery_code' => strtoupper($recovery)])
            ->assertOk();

        $this->assertNotContains($recovery, $user->fresh()->two_factor_recovery_codes);
        $this->assertCount(7, $user->fresh()->two_factor_recovery_codes);
        $this->assertDatabaseHas('audit_logs', ['action' => 'two_factor_recovery_code_used', 'actor_id' => $user->id]);

        $this->postJson('/api/v1/auth/logout');
        $challenge = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])->json('challenge');
        $this->postJson('/api/v1/auth/two-factor-challenge', ['challenge' => $challenge, 'recovery_code' => $recovery])
            ->assertStatus(422);
    }

    public function test_a_tampered_or_expired_challenge_is_rejected(): void
    {
        [$user, $secret] = $this->userWithTwoFactor();

        $this->postJson('/api/v1/auth/two-factor-challenge', ['challenge' => 'forged', 'code' => $this->otp($secret)])
            ->assertStatus(422);

        $challenge = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])->json('challenge');
        $this->travel(6)->minutes();

        $this->postJson('/api/v1/auth/two-factor-challenge', ['challenge' => $challenge, 'code' => $this->otp($secret)])
            ->assertStatus(422);
        $this->assertGuest();
    }

    public function test_wrong_codes_are_limited_per_challenge(): void
    {
        [$user, $secret] = $this->userWithTwoFactor();
        $challenge = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])->json('challenge');

        // The route throttle allows five requests a minute, so take the last
        // allowed wrong attempt by bumping the counter directly.
        foreach (range(1, 4) as $_) {
            $this->postJson('/api/v1/auth/two-factor-challenge', ['challenge' => $challenge, 'code' => '000000'])->assertStatus(422);
        }
        cache()->increment('two-factor:challenge:'.$this->nonce($challenge));

        $this->postJson('/api/v1/auth/two-factor-challenge', ['challenge' => $challenge, 'code' => $this->otp($secret)])
            ->assertStatus(422)
            ->assertJsonPath('errors.code.0', 'Too many incorrect codes. Please sign in again.');
    }

    public function test_disabling_requires_the_password(): void
    {
        [$user] = $this->userWithTwoFactor();
        $this->actingAs($user);

        $this->deleteJson('/api/v1/auth/two-factor', ['password' => 'nope'])->assertStatus(422);
        $this->deleteJson('/api/v1/auth/two-factor', ['password' => 'password'])->assertOk();

        $this->assertFalse($user->fresh()->hasTwoFactorEnabled());
    }

    public function test_a_firm_administrator_can_reset_a_colleagues_two_factor(): void
    {
        [$user] = $this->userWithTwoFactor();
        $firm = Firm::find($user->firm_id);

        $this->signIn(Role::Associate, $firm);
        $this->deleteJson("/api/v1/users/{$user->id}/two-factor")->assertForbidden();

        $this->signIn(Role::ManagingPartner, $firm);
        $this->deleteJson("/api/v1/users/{$user->id}/two-factor")->assertOk();
        $this->assertFalse($user->fresh()->hasTwoFactorEnabled());

        // Another firm's user is not visible at all.
        $this->signIn(Role::ManagingPartner);
        $this->deleteJson("/api/v1/users/{$user->id}/two-factor")->assertNotFound();
    }

    /** @return array{User, string} */
    private function userWithTwoFactor(): array
    {
        $secret = app(Google2FA::class)->generateSecretKey(32);
        $user = User::factory()->role(Role::Associate)->create([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => ['abcde-fghij', 'klmno-pqrst', 'uvwxy-zabcd', 'efghi-jklmn', 'opqrs-tuvwx', 'yzabc-defgh', 'ijklm-nopqr', 'stuvw-xyzab'],
            'two_factor_confirmed_at' => now(),
        ]);

        return [$user, $secret];
    }

    private function otp(string $secret): string
    {
        return app(Google2FA::class)->getCurrentOtp($secret);
    }

    private function nonce(string $challenge): string
    {
        return json_decode(decrypt($challenge, false), true)['nonce'];
    }
}
