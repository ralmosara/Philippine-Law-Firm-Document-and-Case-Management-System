<?php

namespace Tests\Feature\Directory;

use App\Domain\Compliance\Services\ConflictChecker;
use App\Domain\Directory\Models\Contact;
use App\Domain\Matters\Enums\PartyRole;
use App\Domain\Matters\Models\Client;
use App\Domain\Matters\Models\Firm;
use App\Domain\Matters\Models\Matter;
use App\Enums\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DirectoryTest extends TestCase
{
    use RefreshDatabase;

    private Firm $firm;

    private Matter $matter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->firm = Firm::factory()->create();
        $this->signIn(Role::Associate, $this->firm);
        $this->matter = Matter::factory()->for(Client::factory()->for($this->firm))->create(['firm_id' => $this->firm->id]);
    }

    private function court(array $data = []): array
    {
        return $this->postJson('/api/v1/directory/courts', ['level' => 'rtc', 'name' => 'Regional Trial Court', 'branch' => 'Branch 143', 'station' => 'Makati City', 'phone' => '(02) 8888-1234', ...$data])
            ->assertCreated()->json();
    }

    private function contact(array $data): array
    {
        return $this->postJson('/api/v1/directory/contacts', $data)->assertCreated()->json();
    }

    public function test_setting_a_matters_court_fills_the_caption_fields_and_the_judge(): void
    {
        $court = $this->court();
        $this->assertSame('Regional Trial Court, Makati City', $court['label']);
        $judge = $this->contact(['kind' => 'judge', 'title' => 'Hon.', 'name' => 'Maria Elena Reyes', 'court_id' => $court['id']]);
        $this->contact(['kind' => 'clerk_of_court', 'name' => 'Jose Bautista', 'court_id' => $court['id'], 'phone' => '0917 000 0000']);

        $this->putJson("/api/v1/matters/{$this->matter->id}/court", ['court_id' => $court['id']])->assertOk()
            ->assertJsonPath('court.label', 'Regional Trial Court, Makati City')
            ->assertJsonPath('contacts.0.role', 'judge')
            ->assertJsonPath('contacts.0.contact.id', $judge['id']);

        $this->matter->refresh();
        $this->assertSame('Regional Trial Court, Makati City', $this->matter->court);
        $this->assertSame('Branch 143', $this->matter->court_branch);
        $this->assertSame('Hon. Maria Elena Reyes', $this->matter->judge);

        $detail = $this->getJson("/api/v1/directory/courts/{$court['id']}")->assertOk();
        $this->assertCount(2, $detail->json('contacts'));
        $this->assertSame($this->matter->id, $detail->json('matters.0.id'));
    }

    public function test_contacts_are_linked_to_matters_and_searchable(): void
    {
        $counsel = $this->contact(['kind' => 'opposing_counsel', 'title' => 'Atty.', 'name' => 'Ramon dela Paz', 'organization' => 'Dela Paz & Associates', 'roll_number' => '54321']);
        $this->postJson("/api/v1/matters/{$this->matter->id}/contacts", ['contact_id' => $counsel['id'], 'role' => 'opposing_counsel'])->assertCreated()
            ->assertJsonPath('contacts.0.contact.display_name', 'Atty. Ramon dela Paz');
        // Linking twice is harmless.
        $this->postJson("/api/v1/matters/{$this->matter->id}/contacts", ['contact_id' => $counsel['id'], 'role' => 'opposing_counsel'])->assertOk();

        $this->assertSame('Ramon dela Paz', $this->getJson('/api/v1/directory/contacts?search=associates')->json('data.0.name'));
        $this->assertSame([], $this->getJson('/api/v1/directory/contacts?kind=judge')->json('data'));
        $this->assertSame(1, $this->getJson("/api/v1/directory/contacts/{$counsel['id']}")->json('matters_count'));

        // Someone who appeared in a matter is retired, not deleted.
        $this->deleteJson("/api/v1/directory/contacts/{$counsel['id']}")->assertOk()->assertJsonPath('is_active', false);
        $this->assertSame([], $this->getJson('/api/v1/directory/contacts')->json('data'));
        $this->assertCount(1, $this->getJson('/api/v1/directory/contacts?include_inactive=1')->json('data'));

        $link = $this->getJson("/api/v1/matters/{$this->matter->id}/contacts")->json('contacts.0.id');
        $this->deleteJson("/api/v1/matter-contacts/{$link}")->assertNoContent();
    }

    public function test_conflict_checks_cover_opposing_counsel(): void
    {
        $counsel = $this->contact(['kind' => 'opposing_counsel', 'name' => 'Ramon dela Paz', 'organization' => 'Dela Paz & Associates']);
        $this->postJson("/api/v1/matters/{$this->matter->id}/contacts", ['contact_id' => $counsel['id'], 'role' => 'opposing_counsel'])->assertCreated();
        $other = Matter::factory()->for(Client::factory()->for($this->firm))->create(['firm_id' => $this->firm->id]);
        $other->parties()->create(['role' => PartyRole::AdverseParty, 'name' => 'Pedro Santos', 'counsel_name' => 'Atty. Lourdes Villanueva']);

        $check = app(ConflictChecker::class)->check($this->firm->id, 'Ramon de la Paz', null);
        $this->assertSame('Opposing counsel', $check->matches[0]['relationship']);
        $this->assertTrue($check->matches[0]['is_adverse']);
        $this->assertSame($this->matter->id, $check->matches[0]['matter_id']);

        $check = app(ConflictChecker::class)->check($this->firm->id, 'Lourdes Villanueva', null);
        $this->assertStringContainsString('counsel for Pedro Santos', $check->matches[0]['reason']);
    }

    public function test_staff_cannot_edit_and_firms_are_separate(): void
    {
        $court = $this->court();
        $this->signIn(Role::Staff, $this->firm);
        $this->getJson('/api/v1/directory/courts')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson('/api/v1/directory/contacts', ['kind' => 'judge', 'name' => 'X'])->assertForbidden();

        $this->signIn(Role::ManagingPartner, Firm::factory()->create());
        $this->getJson('/api/v1/directory/courts')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/directory/courts/{$court['id']}")->assertNotFound();
        $this->postJson('/api/v1/directory/contacts', ['kind' => 'judge', 'name' => 'X', 'court_id' => $court['id']])->assertStatus(422);
        $this->assertSame(0, Contact::withoutGlobalScopes()->where('name', 'X')->count());
    }
}
