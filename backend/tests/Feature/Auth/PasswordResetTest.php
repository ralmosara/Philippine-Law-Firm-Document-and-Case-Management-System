<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_reset_link_to_the_app_is_emailed_and_the_response_never_reveals_accounts(): void
    {
        Notification::fake();
        config(['app.frontend_url' => 'https://app.lex.ph']);
        $user = User::factory()->create(['email' => 'atty@firm.ph']);

        $known = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'atty@firm.ph'])->assertOk();
        $unknown = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'ghost@firm.ph'])->assertOk();

        $this->assertSame($known->json('message'), $unknown->json('message'));
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
            $url = $notification->toMail($user)->actionUrl;

            return str_starts_with($url, 'https://app.lex.ph/reset-password?token=') && str_contains($url, 'email=atty%40firm.ph');
        });
        Notification::assertCount(1);
    }

    public function test_deactivated_accounts_get_no_link(): void
    {
        Notification::fake();
        User::factory()->create(['email' => 'former@firm.ph', 'is_active' => false]);

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'former@firm.ph'])->assertOk();

        Notification::assertNothingSent();
    }

    public function test_the_token_resets_the_password_once(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'atty@firm.ph']);
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'atty@firm.ph']);

        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use (&$token) {
            $token = $notification->token;

            return true;
        });

        $reset = ['token' => $token, 'email' => 'atty@firm.ph', 'password' => 'n3w-Passw0rd!', 'password_confirmation' => 'n3w-Passw0rd!'];

        $this->postJson('/api/v1/auth/reset-password', [...$reset, 'token' => 'wrong'])->assertStatus(422);
        $this->postJson('/api/v1/auth/reset-password', $reset)->assertOk();
        $this->postJson('/api/v1/auth/reset-password', $reset)->assertStatus(422);

        $this->assertDatabaseHas('audit_logs', ['action' => 'password_reset', 'subject_id' => $user->id]);
        $this->postJson('/api/v1/auth/login', ['email' => 'atty@firm.ph', 'password' => 'n3w-Passw0rd!'])->assertOk();
    }
}
