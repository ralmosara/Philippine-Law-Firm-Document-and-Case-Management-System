<?php

namespace Tests\Feature\Auth;

use App\Domain\Matters\Models\Client;
use App\Domain\Matters\Models\Firm;
use App\Domain\Matters\Notifications\PortalPasswordLink;
use App\Enums\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PortalPasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        config(['app.frontend_url' => 'https://app.lex.ph']);
    }

    public function test_a_client_of_two_firms_gets_a_separate_link_for_each_portal_account(): void
    {
        $first = Client::factory()->for(Firm::factory()->state(['name' => 'Santos Law']))->withPortal('old-pass-1')->create(['email' => 'juan@example.com']);
        $second = Client::factory()->for(Firm::factory()->state(['name' => 'Reyes & Co.']))->withPortal('old-pass-2')->create(['email' => 'juan@example.com']);
        Client::factory()->create(['email' => 'juan@example.com', 'portal_enabled' => false]);

        $known = $this->postJson('/api/portal/forgot-password', ['email' => 'juan@example.com'])->assertOk()->json('message');
        $unknown = $this->postJson('/api/portal/forgot-password', ['email' => 'nobody@example.com'])->assertOk()->json('message');
        $this->assertSame($known, $unknown);

        Notification::assertSentTo($first, PortalPasswordLink::class, fn (PortalPasswordLink $n) => $n->firmName === 'Santos Law'
            && str_starts_with($n->url(), "https://app.lex.ph/portal/reset-password?client={$first->id}&token="));
        Notification::assertSentTo($second, PortalPasswordLink::class, fn (PortalPasswordLink $n) => $n->firmName === 'Reyes & Co.');
        Notification::assertCount(2);
    }

    public function test_the_link_sets_a_new_password_once(): void
    {
        $client = Client::factory()->withPortal('old-password')->create(['email' => 'juan@example.com']);
        $token = $this->requestToken($client);
        $reset = ['client' => $client->id, 'token' => $token, 'password' => 'n3w-Portal-pass', 'password_confirmation' => 'n3w-Portal-pass'];

        $this->postJson('/api/portal/reset-password', [...$reset, 'token' => 'wrong'])->assertStatus(422);
        $this->postJson('/api/portal/reset-password', [...$reset, 'client' => $client->id + 1])->assertStatus(422);
        $this->postJson('/api/portal/reset-password', $reset)->assertOk();
        $this->postJson('/api/portal/reset-password', $reset)->assertStatus(422); // used up

        $this->postJson('/api/portal/login', ['email' => 'juan@example.com', 'password' => 'n3w-Portal-pass'])->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'portal_password_reset', 'actor_id' => $client->id]);
    }

    public function test_reset_links_expire_after_an_hour(): void
    {
        $client = Client::factory()->withPortal('old-password')->create();
        $token = $this->requestToken($client);
        $this->travel(61)->minutes();

        $this->postJson('/api/portal/reset-password', ['client' => $client->id, 'token' => $token, 'password' => 'n3w-Portal-pass', 'password_confirmation' => 'n3w-Portal-pass'])
            ->assertStatus(422);
    }

    public function test_the_firm_can_invite_a_client_to_choose_their_own_password(): void
    {
        $firm = Firm::factory()->create();
        $client = Client::factory()->for($firm)->create(['email' => 'maria@example.com']);
        $this->signIn(Role::Associate, $firm);

        $this->putJson("/api/v1/clients/{$client->id}/portal-access", ['portal_enabled' => true])->assertStatus(422); // password or invite needed
        $this->putJson("/api/v1/clients/{$client->id}/portal-access", ['portal_enabled' => true, 'send_invite' => true])
            ->assertOk()
            ->assertJsonPath('portal_enabled', true)
            ->assertJsonPath('portal_password_set', false);

        $token = null;
        Notification::assertSentTo($client, PortalPasswordLink::class, function (PortalPasswordLink $n) use (&$token) {
            $token = $n->token;

            return $n->invite && str_contains($n->url(), '&invite=1');
        });

        // No password yet, so no sign-in; invitations last a week.
        $this->postJson('/api/portal/login', ['email' => 'maria@example.com', 'password' => ''])->assertStatus(422);
        $this->travel(6)->days();
        $this->postJson('/api/portal/reset-password', ['client' => $client->id, 'token' => $token, 'password' => 'Chosen-pass-123', 'password_confirmation' => 'Chosen-pass-123'])->assertOk();
        $this->postJson('/api/portal/login', ['email' => 'maria@example.com', 'password' => 'Chosen-pass-123'])->assertOk();
    }

    public function test_links_are_throttled_per_account(): void
    {
        $client = Client::factory()->withPortal('pw')->create(['email' => 'juan@example.com']);

        $this->postJson('/api/portal/forgot-password', ['email' => 'juan@example.com']);
        $this->postJson('/api/portal/forgot-password', ['email' => 'juan@example.com']);

        Notification::assertSentToTimes($client, PortalPasswordLink::class, 1);
    }

    private function requestToken(Client $client): string
    {
        $this->postJson('/api/portal/forgot-password', ['email' => $client->email])->assertOk();

        $token = null;
        Notification::assertSentTo($client, PortalPasswordLink::class, function (PortalPasswordLink $n) use (&$token) {
            $token = $n->token;

            return true;
        });

        return $token;
    }
}
