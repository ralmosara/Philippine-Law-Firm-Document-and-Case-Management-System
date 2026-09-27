<?php

namespace Tests\Feature\Auth;

use App\Domain\Matters\Models\Firm;
use App\Enums\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class RequireTwoFactorTest extends TestCase
{
    use RefreshDatabase;

    private Firm $firm;

    protected function setUp(): void
    {
        parent::setUp();
        $this->firm = Firm::factory()->create();
    }

    public function test_an_administrator_must_use_two_factor_before_requiring_it(): void
    {
        $admin = $this->signIn(Role::ManagingPartner, $this->firm);

        $this->putJson('/api/v1/firm', ['require_two_factor' => true])
            ->assertStatus(422)->assertJsonValidationErrors('require_two_factor');

        $admin->forceFill($this->enrolled())->save();

        $this->putJson('/api/v1/firm', ['require_two_factor' => true])
            ->assertOk()
            ->assertJsonPath('require_two_factor', true)
            ->assertJsonPath('users_without_two_factor', 0);
        $this->assertDatabaseHas('audit_logs', ['action' => 'firm_settings_updated', 'firm_id' => $this->firm->id]);
    }

    public function test_only_administrators_change_firm_settings(): void
    {
        $this->signIn(Role::Partner, $this->firm);

        $this->getJson('/api/v1/firm')->assertOk();
        $this->putJson('/api/v1/firm', ['name' => 'Renamed'])->assertForbidden();
    }

    public function test_users_without_two_factor_can_only_set_it_up(): void
    {
        $this->firm->update(['require_two_factor' => true]);
        $user = $this->signIn(Role::Associate, $this->firm)->fresh();
        $this->actingAs($user);

        $this->getJson('/api/v1/matters')
            ->assertForbidden()
            ->assertJsonPath('code', 'two_factor_required');

        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('firm.require_two_factor', true);
        $secret = $this->postJson('/api/v1/auth/two-factor', ['password' => 'password'])->assertOk()->json('secret');
        $this->postJson('/api/v1/auth/two-factor/confirm', ['code' => app(Google2FA::class)->getCurrentOtp($secret)])->assertOk();

        $this->actingAs($user->fresh());
        $this->getJson('/api/v1/matters')->assertOk();
    }

    public function test_two_factor_cannot_be_turned_off_while_required(): void
    {
        $this->firm->update(['require_two_factor' => true]);
        $this->signIn(Role::Associate, $this->firm, $this->enrolled());

        $this->deleteJson('/api/v1/auth/two-factor', ['password' => 'password'])
            ->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_other_firms_are_unaffected(): void
    {
        $this->firm->update(['require_two_factor' => true]);

        $this->signIn(Role::Associate);
        $this->getJson('/api/v1/matters')->assertOk();
    }

    private function enrolled(): array
    {
        return [
            'two_factor_secret' => app(Google2FA::class)->generateSecretKey(32),
            'two_factor_recovery_codes' => ['aaaaa-bbbbb'],
            'two_factor_confirmed_at' => now(),
        ];
    }
}
