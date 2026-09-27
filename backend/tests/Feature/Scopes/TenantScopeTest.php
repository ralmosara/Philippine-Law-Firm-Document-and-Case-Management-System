<?php

namespace Tests\Feature\Scopes;

use App\Domain\Matters\Models\Client;
use App\Domain\Matters\Models\Firm;
use App\Domain\Matters\Models\Matter;
use App\Domain\Trust\Models\TrustAccount;
use App\Enums\Role;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

/**
 * Firm A must never see or touch Firm B's data, whichever way it asks.
 */
class TenantScopeTest extends TestCase
{
    use RefreshDatabase;

    private Firm $firmA;

    private Firm $firmB;

    private Matter $matterB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->firmA = Firm::factory()->create();
        $this->firmB = Firm::factory()->create();

        Matter::factory()->for(Client::factory()->for($this->firmA))->count(2)->create();
        $this->matterB = Matter::factory()->for(Client::factory()->for($this->firmB))->create();
    }

    public function test_the_scope_filters_queries_to_the_current_firm(): void
    {
        $context = app(TenantContext::class);

        $this->assertCount(2, $context->runAs($this->firmA->id, fn () => Matter::all()));
        $this->assertCount(1, $context->runAs($this->firmB->id, fn () => Matter::all()));
        $this->assertCount(3, Matter::all(), 'Without a tenant (console, workers) the scope is inert.');
    }

    public function test_new_records_are_stamped_with_the_current_firm(): void
    {
        $client = app(TenantContext::class)->runAs($this->firmA->id, fn () => Client::create(['name' => 'Stamped', 'type' => 'individual']));

        $this->assertSame($this->firmA->id, $client->firm_id);
    }

    public function test_writing_into_another_firm_is_refused(): void
    {
        $this->expectException(LogicException::class);

        app(TenantContext::class)->runAs($this->firmA->id, fn () => Client::create([
            'firm_id' => $this->firmB->id,
            'name' => 'Smuggled',
            'type' => 'individual',
        ]));
    }

    public function test_lists_only_contain_the_users_own_firm(): void
    {
        $this->signIn(Role::Associate, $this->firmA);

        $this->getJson('/api/v1/matters')->assertOk()->assertJsonCount(2, 'data');
        // Both of firm A's matters belong to one (factory-recycled) client.
        $this->getJson('/api/v1/clients')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_another_firms_records_are_not_found_by_id(): void
    {
        $this->signIn(Role::ManagingPartner, $this->firmA);
        $account = TrustAccount::factory()->create(['client_id' => $this->matterB->client_id]);

        $this->getJson("/api/v1/matters/{$this->matterB->id}")->assertNotFound()->assertJsonPath('message', 'Resource not found.');
        $this->getJson("/api/v1/clients/{$this->matterB->client_id}")->assertNotFound();
        $this->putJson("/api/v1/matters/{$this->matterB->id}", ['title' => 'Hijacked'])->assertNotFound();
        $this->deleteJson("/api/v1/matters/{$this->matterB->id}")->assertNotFound();
        $this->getJson("/api/v1/trust-accounts/{$account->id}")->assertNotFound();
        $this->postJson("/api/v1/trust-accounts/{$account->id}/transactions", [
            'type' => 'disbursement', 'amount_cents' => 100, 'description' => 'Theft',
        ])->assertNotFound();

        $this->assertSame($this->matterB->title, $this->matterB->fresh()->title);
    }

    public function test_cannot_open_a_matter_for_another_firms_client(): void
    {
        $this->signIn(Role::Associate, $this->firmA);

        $this->postJson('/api/v1/matters', [
            'client_id' => $this->matterB->client_id,
            'title' => 'Cross-tenant',
            'case_type' => 'Civil',
        ])->assertStatus(422)->assertJsonValidationErrors('client_id');
    }

    public function test_users_of_another_firm_are_invisible(): void
    {
        $this->signIn(Role::ManagingPartner, $this->firmA);
        $outsider = User::factory()->create(['firm_id' => $this->firmB->id]);

        $this->getJson("/api/v1/users/{$outsider->id}")->assertNotFound();
        $this->getJson('/api/v1/users')->assertJsonMissing(['id' => $outsider->id]);
    }
}
