<?php

namespace Tests\Feature\Evidence;

use App\Domain\Documents\Models\MatterFile;
use App\Domain\Evidence\ExhibitMarkings;
use App\Domain\Evidence\Models\Exhibit;
use App\Domain\Matters\Enums\PartyRole;
use App\Domain\Matters\Models\Client;
use App\Domain\Matters\Models\Firm;
use App\Domain\Matters\Models\Matter;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExhibitTest extends TestCase
{
    use RefreshDatabase;

    private Firm $firm;

    private User $lawyer;

    private Matter $matter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->firm = Firm::factory()->create();
        $this->lawyer = $this->signIn(Role::Associate, $this->firm);
        $client = Client::factory()->for($this->firm)->create(['name' => 'Juan dela Cruz']);
        $this->matter = Matter::factory()->for($client)->create([
            'firm_id' => $this->firm->id, 'title' => 'Dela Cruz v. Santos', 'case_type' => 'Civil', 'client_role' => 'plaintiff',
            'court' => 'Regional Trial Court, Makati City', 'case_number' => 'R-MKT-26-00123-CV', 'responsible_lawyer_id' => $this->lawyer->id,
        ]);
        $this->matter->parties()->create(['role' => PartyRole::AdverseParty, 'name' => 'Pedro Santos', 'counsel_name' => 'Atty. Reyes']);
    }

    private function mark(array $data): array
    {
        return $this->postJson("/api/v1/matters/{$this->matter->id}/exhibits", ['side' => 'ours', 'description' => 'Document', ...$data])->assertCreated()->json();
    }

    public function test_markings_follow_philippine_practice(): void
    {
        // The plaintiff marks with letters, the defendant with numbers.
        $this->assertSame('A', $this->mark(['description' => 'Deed of Absolute Sale dated 3 March 2024'])['marking']);
        $this->assertSame('B', $this->mark([])['marking']);
        $this->assertSame('A-1', $this->mark(['parent' => 'A', 'description' => 'Signature of the defendant on page 2'])['marking']);
        $this->assertSame('A-2', $this->mark(['parent' => 'A'])['marking']);
        $this->assertSame('A-1-a', $this->mark(['parent' => 'A-1'])['marking']);
        $this->assertSame('1', $this->mark(['side' => 'adverse'])['marking']);
        $this->assertSame('1-a', $this->mark(['side' => 'adverse', 'parent' => '1'])['marking']);

        // A marking already used on that side is refused; the other side may use it.
        $this->postJson("/api/v1/matters/{$this->matter->id}/exhibits", ['side' => 'ours', 'marking' => 'B', 'description' => 'Duplicate'])
            ->assertStatus(422)->assertJsonValidationErrors('marking');

        $list = $this->getJson("/api/v1/matters/{$this->matter->id}/exhibits")->assertOk();
        $this->assertSame(['A', 'A-1', 'A-1-a', 'A-2', 'B', '1', '1-a'], array_column($list->json('exhibits'), 'marking'));
        $this->assertSame('C', $list->json('next.ours'));

        // The accused in a criminal case marks with numbers; the prosecution with letters.
        $markings = app(ExhibitMarkings::class);
        $criminal = new Matter(['case_type' => 'Criminal', 'client_role' => 'accused']);
        $this->assertFalse($markings->usesLetters($criminal, 'ours'));
        $this->assertTrue($markings->usesLetters($criminal, 'adverse'));
        $this->assertFalse($markings->usesLetters(new Matter(['case_type' => 'Civil', 'client_role' => 'respondent']), 'ours'));

        // Past Z come AA, BB; order stays natural.
        $this->assertSame(-1, $markings->compare('Z', 'AA'));
        $this->assertSame(-1, $markings->compare('2', '10'));
        $this->assertSame(-1, $markings->compare('A-2', 'A-10'));
    }

    public function test_objections_and_rulings_are_tracked(): void
    {
        $a = $this->mark(['description' => 'Contract'])['id'];
        $b = $this->mark(['description' => 'Demand letter'])['id'];
        $c = $this->mark(['description' => 'Photocopy of receipt'])['id'];

        $this->patchJson("/api/v1/exhibits/{$c}", ['status' => 'offered', 'objection' => 'Not the original; best evidence rule.'])
            ->assertOk()->assertJsonPath('objection', 'Not the original; best evidence rule.');

        $this->postJson("/api/v1/matters/{$this->matter->id}/exhibits/status", ['ids' => [$a, $b], 'status' => 'admitted', 'ruled_on' => today()->toDateString(), 'ruling' => 'Order dated today'])
            ->assertOk()->assertJsonPath('updated', 2);
        $this->postJson("/api/v1/matters/{$this->matter->id}/exhibits/status", ['ids' => [$c], 'status' => 'denied'])->assertOk();

        $this->assertSame('admitted', Exhibit::find($a)->status);
        $this->assertSame('denied', Exhibit::find($c)->status);
        $this->assertNotNull(Exhibit::find($c)->ruled_on);

        // Ruled-on exhibits cannot be deleted, only withdrawn.
        $this->deleteJson("/api/v1/exhibits/{$a}")->assertStatus(422);

        // Exhibits of another matter cannot be swept into this one's ruling.
        $other = Matter::factory()->create(['firm_id' => $this->firm->id]);
        $foreign = Exhibit::create(['firm_id' => $this->firm->id, 'matter_id' => $other->id, 'side' => 'ours', 'marking' => 'A', 'description' => 'X']);
        $this->postJson("/api/v1/matters/{$this->matter->id}/exhibits/status", ['ids' => [$foreign->id], 'status' => 'admitted'])->assertStatus(422);
    }

    public function test_exhibits_link_only_to_the_matters_own_files(): void
    {
        $file = MatterFile::create(['firm_id' => $this->firm->id, 'matter_id' => $this->matter->id, 'original_name' => 'deed.pdf', 'path' => 'x/deed.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 10, 'sha256' => str_repeat('a', 64)]);
        $other = Matter::factory()->create(['firm_id' => $this->firm->id]);
        $foreign = MatterFile::create(['firm_id' => $this->firm->id, 'matter_id' => $other->id, 'original_name' => 'other.pdf', 'path' => 'x/other.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 10, 'sha256' => str_repeat('b', 64)]);

        $this->assertSame('deed.pdf', $this->mark(['matter_file_id' => $file->id])['file_name']);
        $this->postJson("/api/v1/matters/{$this->matter->id}/exhibits", ['side' => 'ours', 'description' => 'X', 'matter_file_id' => $foreign->id])
            ->assertStatus(422)->assertJsonValidationErrors('matter_file_id');
    }

    public function test_the_formal_offer_is_drafted_from_the_exhibits(): void
    {
        $this->mark(['description' => 'Deed of Absolute Sale dated 3 March 2024', 'purpose' => 'To prove the sale of the property to the plaintiff', 'witness' => 'Juan dela Cruz']);
        $this->mark(['parent' => 'A', 'description' => "Defendant's signature on page 2", 'purpose' => 'To prove the defendant signed the deed']);
        $this->mark(['description' => 'Demand letter dated 5 May 2025']);
        $this->mark(['side' => 'adverse', 'description' => 'Their receipt']);
        $withdrawn = $this->mark(['description' => 'Withdrawn photo'])['id'];
        $this->patchJson("/api/v1/exhibits/{$withdrawn}", ['status' => 'withdrawn'])->assertOk();

        $text = $this->postJson("/api/v1/matters/{$this->matter->id}/exhibits/formal-offer/preview")->assertOk()->json('text');

        $this->assertStringContainsString('FORMAL OFFER OF EVIDENCE', $text);
        $this->assertStringContainsString('R-MKT-26-00123-CV', $text);
        $this->assertStringContainsString('Exhibit "A" — Deed of Absolute Sale dated 3 March 2024', $text);
        $this->assertStringContainsString('Identified by: Juan dela Cruz', $text);
        $this->assertStringContainsString('    Exhibit "A-1" — Defendant\'s signature on page 2', $text);
        $this->assertStringContainsString('Purpose: [State the purpose', $text);
        $this->assertStringContainsString('that Exhibits "A", "A-1" and "B" be admitted', $text);
        $this->assertStringNotContainsString('Their receipt', $text);
        $this->assertStringNotContainsString('Withdrawn photo', $text);
        $this->assertStringNotContainsString('VERIFICATION', $text);
        $this->assertStringContainsString('ATTY. REYES', $text); // copy furnished

        $doc = $this->postJson("/api/v1/matters/{$this->matter->id}/exhibits/formal-offer")->assertCreated()->json();
        $this->assertSame('Formal Offer of Evidence — Dela Cruz v. Santos', $doc['title']);

        $csv = $this->get("/api/v1/matters/{$this->matter->id}/exhibits.csv")->assertOk()->streamedContent();
        $this->assertStringContainsString('Ours,A,"Deed of Absolute Sale dated 3 March 2024"', $csv);
        $this->assertStringContainsString('"Other side",1,"Their receipt"', $csv);
    }

    public function test_staff_cannot_mark_and_other_firms_cannot_see(): void
    {
        $id = $this->mark([])['id'];

        $this->signIn(Role::Staff, $this->firm);
        $this->getJson("/api/v1/matters/{$this->matter->id}/exhibits")->assertOk();
        $this->postJson("/api/v1/matters/{$this->matter->id}/exhibits", ['side' => 'ours', 'description' => 'X'])->assertForbidden();

        $this->signIn(Role::ManagingPartner, Firm::factory()->create());
        $this->getJson("/api/v1/matters/{$this->matter->id}/exhibits")->assertNotFound();
        $this->patchJson("/api/v1/exhibits/{$id}", ['status' => 'withdrawn'])->assertNotFound();
    }
}
