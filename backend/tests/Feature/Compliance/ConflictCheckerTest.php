<?php

namespace Tests\Feature\Compliance;

use App\Domain\Compliance\Enums\ConflictCheckStatus;
use App\Domain\Compliance\Services\ConflictChecker;
use App\Domain\Matters\Models\Client;
use App\Domain\Matters\Models\Firm;
use App\Domain\Matters\Models\Matter;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ConflictCheckerTest extends TestCase
{
    use RefreshDatabase;

    private Firm $firm;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->firm = Firm::factory()->create();
        $this->user = User::factory()->create(['firm_id' => $this->firm->id]);
    }

    public function test_matches_clients_regardless_of_name_order_or_case(): void
    {
        Client::factory()->for($this->firm)->create(['name' => 'DELA CRUZ, Juan']);

        $check = app(ConflictChecker::class)->check($this->firm->id, 'juan dela cruz', $this->user);

        $this->assertSame(ConflictCheckStatus::Flagged, $check->status);
        $this->assertSame(1, $check->match_count);
        $this->assertSame('client', $check->matches[0]['source']);
        $this->assertFalse($check->matches[0]['is_adverse']);
    }

    public function test_matches_adverse_parties_and_links_their_matter(): void
    {
        $matter = Matter::factory()->for(Client::factory()->for($this->firm))->create();
        $matter->parties()->create(['role' => 'adverse_party', 'name' => 'Pedro Penduko']);

        $check = app(ConflictChecker::class)->check($this->firm->id, 'Penduko', $this->user);

        $this->assertSame('party', $check->matches[0]['source']);
        $this->assertTrue($check->matches[0]['is_adverse']);
        $this->assertSame($matter->reference, $check->matches[0]['matter_reference']);
    }

    public function test_former_clients_still_count(): void
    {
        Client::factory()->for($this->firm)->create(['name' => 'Maria Clara'])->delete();

        $check = app(ConflictChecker::class)->check($this->firm->id, 'Maria Clara', $this->user);

        $this->assertSame('Former client', $check->matches[0]['relationship']);
    }

    public function test_other_firms_data_is_never_searched(): void
    {
        Client::factory()->for(Firm::factory())->create(['name' => 'Juan Dela Cruz']);

        $check = app(ConflictChecker::class)->check($this->firm->id, 'Juan Dela Cruz', $this->user);

        $this->assertSame(ConflictCheckStatus::Clear, $check->status);
        $this->assertSame([], $check->matches);
    }

    public function test_wildcard_only_searches_are_rejected_rather_than_matching_everyone(): void
    {
        Client::factory()->for($this->firm)->create(['name' => 'Anyone At All']);

        $this->expectException(ValidationException::class);

        app(ConflictChecker::class)->check($this->firm->id, '%% __', $this->user);
    }

    public function test_every_search_is_recorded_and_flagged_checks_need_a_lawyers_resolution(): void
    {
        Client::factory()->for($this->firm)->create(['name' => 'John Doe']);
        $this->signIn(Role::Paralegal, $this->firm);

        $id = $this->postJson('/api/v1/conflict-checks', ['name' => 'John Doe'])
            ->assertCreated()
            ->assertJsonPath('status', 'flagged')
            ->json('id');

        $this->postJson("/api/v1/conflict-checks/{$id}/resolve", ['status' => 'waived', 'notes' => 'x'])->assertForbidden();

        $lawyer = $this->signIn(Role::Associate, $this->firm);
        $this->postJson("/api/v1/conflict-checks/{$id}/resolve", ['status' => 'waived', 'notes' => 'Different person; verified by TIN.'])
            ->assertOk()
            ->assertJsonPath('status', 'waived')
            ->assertJsonPath('resolver.id', $lawyer->id);

        $this->postJson("/api/v1/conflict-checks/{$id}/resolve", ['status' => 'declined', 'notes' => 'Changed mind'])->assertStatus(422);
    }
}
