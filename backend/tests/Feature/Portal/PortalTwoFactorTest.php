<?php

namespace Tests\Feature\Portal;

use App\Domain\Matters\Models\Client;
use App\Domain\Matters\Models\Firm;
use App\Enums\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class PortalTwoFactorTest extends TestCase
{
    use RefreshDatabase;

    private Firm $firm;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->firm = Firm::factory()->create();
        // Reloaded, as a real portal session would load it (every column, remember_token included).
        $this->client = Client::factory()->for($this->firm)->withPortal('secret-pass')->create(['email' => 'juan@example.ph'])->fresh();
    }

    private function code(string $secret, int $offset = 0): string
    {
        $engine = app(Google2FA::class);

        // getTimestamp() counts 30-second steps; an offset of 30 seconds is the next step.
        return $engine->oathTotp($secret, $engine->getTimestamp() + intdiv($offset, 30));
    }

    private function enroll(): array
    {
        $this->actingAs($this->client, 'client');
        $this->postJson('/api/portal/two-factor', ['password' => 'wrong'])->assertJsonValidationErrors('password');
        $secret = $this->postJson('/api/portal/two-factor', ['password' => 'secret-pass'])->assertOk()->json('secret');
        $this->postJson('/api/portal/two-factor/confirm', ['code' => '000000'])->assertJsonValidationErrors('code');
        $codes = $this->postJson('/api/portal/two-factor/confirm', ['code' => $this->code($secret)])->assertOk()->json('recovery_codes');
        $this->assertCount(8, $codes);
        Auth::guard('client')->logout();

        return [$secret, $codes];
    }

    public function test_sign_in_asks_for_the_code_once_it_is_on(): void
    {
        [$secret, $codes] = $this->enroll();

        $challenge = $this->postJson('/api/portal/login', ['email' => 'juan@example.ph', 'password' => 'secret-pass'])->assertOk()->assertJsonPath('two_factor', true)->json('challenge');
        $this->assertGuest('client');

        $this->postJson('/api/portal/two-factor-challenge', ['challenge' => $challenge, 'code' => '123456'])->assertJsonValidationErrors('code');
        // The code from the next 30-second step is accepted (clock drift), and cannot be used twice.
        $this->postJson('/api/portal/two-factor-challenge', ['challenge' => $challenge, 'code' => $this->code($secret, 30)])->assertOk()->assertJsonPath('client.two_factor_enabled', true);
        $this->assertAuthenticatedAs($this->client, 'client');

        Auth::guard('client')->logout();
        $challenge = $this->postJson('/api/portal/login', ['email' => 'juan@example.ph', 'password' => 'secret-pass'])->json('challenge');
        $this->postJson('/api/portal/two-factor-challenge', ['challenge' => $challenge, 'recovery_code' => $codes[0]])->assertOk();
        $this->assertCount(7, $this->client->fresh()->two_factor_recovery_codes);
    }

    public function test_a_firm_can_require_it(): void
    {
        $this->firm->update(['portal_two_factor' => 'required']);
        $this->actingAs($this->client, 'client');

        $this->getJson('/api/portal/invoices')->assertForbidden()->assertJsonPath('code', 'two_factor_required');
        $this->getJson('/api/portal/me')->assertOk()->assertJsonPath('client.two_factor_required', true);

        $secret = $this->postJson('/api/portal/two-factor', ['password' => 'secret-pass'])->assertOk()->json('secret');
        $this->postJson('/api/portal/two-factor/confirm', ['code' => $this->code($secret)])->assertOk();
        $this->getJson('/api/portal/invoices')->assertOk();

        $this->postJson('/api/portal/two-factor/disable', ['password' => 'secret-pass'])->assertJsonValidationErrors('password');
    }

    public function test_staff_reset_it_for_a_client_who_lost_their_phone(): void
    {
        $this->enroll();
        $this->signIn(Role::Associate, $this->firm);

        $this->postJson("/api/v1/clients/{$this->client->id}/portal-two-factor/reset")->assertOk();
        $this->assertFalse($this->client->fresh()->hasTwoFactorEnabled());
        $this->postJson('/api/portal/login', ['email' => 'juan@example.ph', 'password' => 'secret-pass'])->assertOk()->assertJsonMissingPath('two_factor');
    }

    public function test_only_a_managing_partner_sets_the_firm_rule(): void
    {
        $this->signIn(Role::ManagingPartner, $this->firm);
        $this->putJson('/api/v1/firm', ['portal_two_factor' => 'required'])->assertOk()->assertJsonPath('portal_two_factor', 'required');
        $this->putJson('/api/v1/firm', ['portal_two_factor' => 'sometimes'])->assertJsonValidationErrors('portal_two_factor');
    }
}
