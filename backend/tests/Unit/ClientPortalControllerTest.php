<?php

namespace Tests\Unit;

use App\Domain\Billing\Models\Invoice;
use App\Domain\Deadlines\Models\MatterDeadline;
use App\Domain\Documents\Actions\CreateDocumentVersion;
use App\Domain\Matters\Models\Client;
use App\Domain\Matters\Models\Firm;
use App\Domain\Matters\Models\Matter;
use App\Enums\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The client portal: clients see only their own matters, and only what the
 * firm has shared.
 */
class ClientPortalControllerTest extends TestCase
{
    use RefreshDatabase;

    private Firm $firm;

    private Client $client;

    private Matter $ownMatter;

    private Matter $otherMatter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->firm = Firm::factory()->create();
        $this->client = Client::factory()->for($this->firm)->withPortal('secret-pass')->create(['email' => 'juan@example.com']);
        $this->ownMatter = Matter::factory()->for($this->client)->create(['title' => 'Own matter']);
        $this->otherMatter = Matter::factory()->for(Client::factory()->for($this->firm))->create(['title' => 'Someone else']);
    }

    public function test_clients_sign_in_with_portal_credentials(): void
    {
        $this->postJson('/api/portal/login', ['email' => 'juan@example.com', 'password' => 'wrong'])->assertStatus(422);

        $this->postJson('/api/portal/login', ['email' => 'juan@example.com', 'password' => 'secret-pass'])
            ->assertOk()
            ->assertJsonPath('client.name', $this->client->name)
            ->assertJsonPath('client.firm.id', $this->firm->id);

        $this->assertAuthenticatedAs($this->client, 'client');
    }

    public function test_clients_without_portal_access_cannot_sign_in(): void
    {
        $this->client->update(['portal_enabled' => false]);

        $this->postJson('/api/portal/login', ['email' => 'juan@example.com', 'password' => 'secret-pass'])->assertStatus(422);
    }

    public function test_client_can_only_see_their_own_matters(): void
    {
        $this->actingAs($this->client, 'client');

        $this->getJson('/api/portal/matters')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Own matter')
            ->assertJsonPath('data.0.progress', 10);

        $this->getJson("/api/portal/matters/{$this->otherMatter->id}")->assertNotFound();
    }

    public function test_clients_see_hearings_but_not_internal_deadlines(): void
    {
        MatterDeadline::factory()->for($this->ownMatter)->hearing()->create(['due_date' => today()->addDays(3)]);
        MatterDeadline::factory()->for($this->ownMatter)->create(['title' => 'Internal: draft reply', 'due_date' => today()->addDay()]);
        $this->actingAs($this->client, 'client');

        $this->getJson("/api/portal/matters/{$this->ownMatter->id}")
            ->assertOk()
            ->assertJsonPath('next_hearing.title', 'Hearing')
            ->assertJsonMissing(['title' => 'Internal: draft reply']);
    }

    public function test_only_shared_documents_are_visible(): void
    {
        $shared = $this->ownMatter->documents()->create(['firm_id' => $this->firm->id, 'title' => 'Signed retainer', 'shared_with_client' => true]);
        app(CreateDocumentVersion::class)->execute($shared, 'Agreement text');
        $private = $this->ownMatter->documents()->create(['firm_id' => $this->firm->id, 'title' => 'Internal memo']);
        $this->actingAs($this->client, 'client');

        $this->getJson("/api/portal/matters/{$this->ownMatter->id}")
            ->assertJsonCount(1, 'documents')
            ->assertJsonPath('documents.0.title', 'Signed retainer');
        $this->getJson("/api/portal/documents/{$shared->id}")->assertOk()->assertJsonPath('content', 'Agreement text');
        $this->getJson("/api/portal/documents/{$private->id}")->assertNotFound();
    }

    public function test_draft_invoices_are_hidden_from_clients(): void
    {
        foreach (['draft', 'issued'] as $i => $status) {
            Invoice::create([
                'firm_id' => $this->firm->id, 'client_id' => $this->client->id, 'matter_id' => $this->ownMatter->id,
                'number' => "INV-2026-0000{$i}", 'subtotal_cents' => 100, 'vat_cents' => 12, 'total_cents' => 112,
            ])->forceFill(['status' => $status])->save();
        }
        $this->actingAs($this->client, 'client');

        $this->getJson('/api/portal/invoices')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('outstanding_cents', 112);
    }

    public function test_a_portal_session_cannot_reach_staff_routes(): void
    {
        $this->actingAs($this->client, 'client');

        $this->getJson('/api/v1/matters')->assertUnauthorized();
    }

    public function test_a_staff_session_cannot_reach_portal_routes(): void
    {
        $this->signIn(Role::ManagingPartner, $this->firm);

        $this->getJson('/api/portal/matters')->assertUnauthorized();
    }
}
