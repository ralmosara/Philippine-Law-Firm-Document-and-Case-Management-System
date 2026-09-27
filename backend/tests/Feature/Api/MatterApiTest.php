<?php

namespace Tests\Feature\Api;

use App\Domain\Matters\Enums\MatterStatus;
use App\Domain\Matters\Models\CaseWorkflowTemplate;
use App\Domain\Matters\Models\Client;
use App\Domain\Matters\Models\Firm;
use App\Domain\Matters\Models\Matter;
use App\Enums\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class MatterApiTest extends TestCase
{
    use RefreshDatabase;

    private Firm $firm;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->firm = Firm::factory()->create();
        $this->client = Client::factory()->for($this->firm)->create();
    }

    public function test_opening_a_matter_assigns_a_reference_parties_and_history(): void
    {
        $lawyer = $this->signIn(Role::Associate, $this->firm);

        $response = $this->postJson('/api/v1/matters', [
            'client_id' => $this->client->id,
            'title' => 'Santos v. Reyes',
            'case_type' => 'Civil',
            'court' => 'Regional Trial Court',
            'parties' => [
                ['role' => 'adverse_party', 'name' => 'Pedro Reyes'],
            ],
        ])->assertCreated()
            ->assertJsonPath('status', 'intake')
            ->assertJsonPath('responsible_lawyer.id', $lawyer->id)
            ->assertJsonPath('parties.0.name', 'Pedro Reyes')
            ->assertJsonPath('parties.0.is_adverse', true);

        $this->assertMatchesRegularExpression('/^M-\d{4}-0001$/', $response->json('reference'));
        $this->assertDatabaseHas('matter_status_events', ['matter_id' => $response->json('id'), 'to_status' => 'intake', 'changed_by' => $lawyer->id]);
    }

    public function test_references_are_sequential_per_firm(): void
    {
        $this->signIn(Role::Associate, $this->firm);
        $payload = ['client_id' => $this->client->id, 'title' => 'A v. B', 'case_type' => 'Civil'];

        $first = $this->postJson('/api/v1/matters', $payload)->json('reference');
        $second = $this->postJson('/api/v1/matters', $payload)->json('reference');

        $this->assertSame(substr($first, 0, -4).'0002', $second);
    }

    public function test_status_follows_the_state_machine_and_is_recorded(): void
    {
        $lawyer = $this->signIn(Role::Associate, $this->firm);
        $matter = Matter::factory()->for($this->client)->create();

        $this->postJson("/api/v1/matters/{$matter->id}/status", ['status' => 'trial'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        $this->postJson("/api/v1/matters/{$matter->id}/status", ['status' => 'filed'])
            ->assertOk()
            ->assertJsonPath('status', 'filed');

        $this->assertDatabaseHas('matter_status_events', [
            'matter_id' => $matter->id, 'from_status' => 'intake', 'to_status' => 'filed', 'changed_by' => $lawyer->id,
        ]);
    }

    public function test_closing_requires_a_reason_and_sets_the_closed_date(): void
    {
        $this->signIn(Role::Partner, $this->firm);
        $matter = Matter::factory()->for($this->client)->create();

        $this->postJson("/api/v1/matters/{$matter->id}/status", ['status' => 'closed'])
            ->assertStatus(422)->assertJsonValidationErrors('reason');

        $this->postJson("/api/v1/matters/{$matter->id}/status", ['status' => 'closed', 'reason' => 'Amicably settled'])
            ->assertOk()->assertJsonPath('status', 'closed');

        $this->assertNotNull($matter->fresh()->closed_at);
    }

    public function test_status_cannot_be_mass_assigned_through_update(): void
    {
        $this->signIn(Role::Partner, $this->firm);
        $matter = Matter::factory()->for($this->client)->create();

        $this->putJson("/api/v1/matters/{$matter->id}", ['title' => 'Renamed', 'status' => 'closed'])->assertOk();

        $this->assertSame(MatterStatus::Intake, $matter->fresh()->status);
        $this->assertSame('Renamed', $matter->fresh()->title);
    }

    public function test_status_history_is_append_only(): void
    {
        $matter = Matter::factory()->for($this->client)->create();
        $event = $matter->statusEvents()->create(['to_status' => 'intake']);

        $this->expectException(LogicException::class);
        $event->update(['to_status' => 'closed']);
    }

    public function test_workflow_templates_create_tasks_for_their_case_type(): void
    {
        $this->signIn(Role::Associate, $this->firm);
        CaseWorkflowTemplate::create([
            'firm_id' => $this->firm->id,
            'case_type' => 'Annulment',
            'name' => 'Annulment checklist',
            'tasks' => [
                ['title' => 'Psychological evaluation', 'days_offset' => 7],
                ['title' => 'Draft petition', 'days_offset' => 21],
            ],
        ]);

        $id = $this->postJson('/api/v1/matters', [
            'client_id' => $this->client->id, 'title' => 'In re: Marriage', 'case_type' => 'Annulment',
        ])->assertCreated()->json('id');

        $this->getJson("/api/v1/matters/{$id}/deadlines")
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonPath('0.title', 'Psychological evaluation')
            ->assertJsonPath('0.kind', 'task');
    }

    public function test_matters_can_be_searched_and_filtered(): void
    {
        $this->signIn(Role::Associate, $this->firm);
        Matter::factory()->for($this->client)->create(['title' => 'Dela Cruz v. Mercado']);
        Matter::factory()->for($this->client)->status(MatterStatus::Closed)->create(['title' => 'Old matter']);

        $this->getJson('/api/v1/matters?search=mercado')->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Dela Cruz v. Mercado');
        $this->getJson('/api/v1/matters?active_only=1')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/matters?status=closed')->assertJsonCount(1, 'data');
    }

    public function test_parties_are_reachable_only_through_their_own_matter(): void
    {
        $this->signIn(Role::Associate, $this->firm);
        $matter = Matter::factory()->for($this->client)->create();
        $other = Matter::factory()->for($this->client)->create();
        $party = $other->parties()->create(['role' => 'witness', 'name' => 'Juana']);

        $this->putJson("/api/v1/matters/{$matter->id}/parties/{$party->id}", ['name' => 'Changed'])->assertNotFound();
        $this->putJson("/api/v1/matters/{$other->id}/parties/{$party->id}", ['name' => 'Changed'])->assertOk();
    }
}
