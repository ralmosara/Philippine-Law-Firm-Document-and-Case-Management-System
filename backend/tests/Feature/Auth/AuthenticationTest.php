<?php

namespace Tests\Feature\Auth;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_can_sign_in_and_fetch_their_profile(): void
    {
        $user = User::factory()->partner()->create(['email' => 'atty@firm.ph']);

        $this->postJson('/api/v1/auth/login', ['email' => 'atty@firm.ph', 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('user.email', 'atty@firm.ph')
            ->assertJsonPath('user.role', 'partner');

        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('abilities.manage_finances', true)
            ->assertJsonPath('abilities.manage_firm', false);

        $this->assertNotNull($user->fresh()->last_login_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'login', 'actor_id' => $user->id]);
    }

    public function test_wrong_password_and_unknown_email_get_the_same_error(): void
    {
        User::factory()->create(['email' => 'atty@firm.ph']);

        $wrongPassword = $this->postJson('/api/v1/auth/login', ['email' => 'atty@firm.ph', 'password' => 'nope'])->assertStatus(422);
        $unknownEmail = $this->postJson('/api/v1/auth/login', ['email' => 'ghost@firm.ph', 'password' => 'nope'])->assertStatus(422);

        $this->assertSame($wrongPassword->json('errors.email'), $unknownEmail->json('errors.email'));
        $this->assertGuest();
    }

    public function test_deactivated_users_cannot_sign_in(): void
    {
        User::factory()->create(['email' => 'former@firm.ph', 'is_active' => false]);

        $this->postJson('/api/v1/auth/login', ['email' => 'former@firm.ph', 'password' => 'password'])->assertStatus(422);
        $this->assertGuest();
    }

    public function test_deactivated_users_with_a_live_session_are_rejected(): void
    {
        $this->signIn(Role::Associate, attributes: ['is_active' => false]);

        $this->getJson('/api/v1/matters')->assertForbidden();
    }

    public function test_login_is_rate_limited(): void
    {
        User::factory()->create(['email' => 'atty@firm.ph']);

        foreach (range(1, 5) as $_) {
            $this->postJson('/api/v1/auth/login', ['email' => 'atty@firm.ph', 'password' => 'wrong'])->assertStatus(422);
        }

        $this->postJson('/api/v1/auth/login', ['email' => 'atty@firm.ph', 'password' => 'wrong'])
            ->assertStatus(429)
            ->assertJsonPath('status', 'error');
    }

    public function test_protected_routes_require_authentication_with_a_consistent_envelope(): void
    {
        $this->getJson('/api/v1/matters')
            ->assertUnauthorized()
            ->assertExactJson(['status' => 'error', 'message' => 'Unauthenticated.']);
    }

    public function test_users_can_change_their_password(): void
    {
        $user = $this->signIn();

        $this->putJson('/api/v1/auth/password', [
            'current_password' => 'wrong',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertStatus(422)->assertJsonValidationErrors('current_password');

        $this->putJson('/api/v1/auth/password', [
            'current_password' => 'password',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertOk();

        $this->assertTrue(password_verify('new-password-123', $user->fresh()->password));
    }

    public function test_logout_ends_the_session(): void
    {
        $this->signIn();

        $this->postJson('/api/v1/auth/logout')->assertOk();
        $this->assertGuest('web');
    }
}
