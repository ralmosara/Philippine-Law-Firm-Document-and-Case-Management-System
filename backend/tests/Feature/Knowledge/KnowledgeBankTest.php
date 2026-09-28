<?php

namespace Tests\Feature\Knowledge;

use App\Domain\Assistant\MatterContext;
use App\Domain\Documents\Actions\CreateDocumentVersion;
use App\Domain\Knowledge\Models\KnowledgeItem;
use App\Domain\Matters\Models\Client;
use App\Domain\Matters\Models\Firm;
use App\Domain\Matters\Models\Matter;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KnowledgeBankTest extends TestCase
{
    use RefreshDatabase;

    private Firm $firm;

    private User $lawyer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->firm = Firm::factory()->create();
        $this->lawyer = $this->signIn(Role::Associate, $this->firm);
    }

    private function add(array $data): array
    {
        return $this->postJson('/api/v1/knowledge', ['kind' => 'jurisprudence', ...$data])->assertCreated()->json();
    }

    public function test_entries_are_searchable_by_words_citations_and_tags(): void
    {
        $this->add([
            'title' => 'Quasi-delict: employer liability',
            'citation' => 'G.R. No. 123456, March 3, 2020',
            'doctrine' => 'The employer is solidarily liable for the negligence of its employee unless it proves the diligence of a good father of a family.',
            'practice_area' => 'Civil', 'tags' => ['Torts', ' negligence '],
        ]);
        $this->add(['kind' => 'clause', 'title' => 'Arbitration clause (PDRCI)', 'body' => 'Any dispute arising out of this Agreement shall be settled by arbitration.', 'tags' => ['contracts']]);

        $this->assertSame('Quasi-delict: employer liability', $this->getJson('/api/v1/knowledge?search=neglig')->json('data.0.title'));
        $this->assertSame('Quasi-delict: employer liability', $this->getJson('/api/v1/knowledge?search='.urlencode('G.R. No. 123456'))->json('data.0.title'));
        $this->assertCount(1, $this->getJson('/api/v1/knowledge?kind=clause')->json('data'));
        $this->assertCount(1, $this->getJson('/api/v1/knowledge?tag=Torts')->json('data'));
        $this->assertContains('negligence', $this->getJson('/api/v1/knowledge')->json('tags'));
        $this->assertStringContainsString('⟦', (string) $this->getJson('/api/v1/knowledge?search=arbitration')->json('data.0.snippet'));
        $this->assertSame([], $this->getJson('/api/v1/knowledge?search=estafa')->json('data'));
    }

    public function test_a_document_is_kept_as_a_model_pleading(): void
    {
        $matter = Matter::factory()->for(Client::factory()->for($this->firm))->create(['firm_id' => $this->firm->id, 'case_type' => 'Labor']);
        $document = $matter->documents()->create(['firm_id' => $this->firm->id, 'title' => 'Position Paper for Complainant', 'created_by' => $this->lawyer->id]);
        app(CreateDocumentVersion::class)->execute($document, "POSITION PAPER\n\nComplainant was illegally dismissed…", $this->lawyer, 'Draft');

        $item = $this->postJson("/api/v1/documents/{$document->id}/knowledge", ['tags' => ['illegal dismissal']])->assertCreated()->json();
        $this->assertSame('pleading', $item['kind']);
        $this->assertSame('Labor', $item['practice_area']);
        $this->assertStringContainsString('illegally dismissed', $item['body']);
        $this->assertSame($matter->id, $this->getJson("/api/v1/knowledge/{$item['id']}")->json('source_matter.id'));
    }

    public function test_the_assistant_draws_on_entries_for_the_matters_practice_area(): void
    {
        $matter = Matter::factory()->for(Client::factory()->for($this->firm))->create(['firm_id' => $this->firm->id, 'case_type' => 'Civil']);
        $civil = $this->add(['title' => 'Quasi-delict', 'citation' => 'G.R. No. 123456', 'doctrine' => 'Employer is solidarily liable.', 'practice_area' => 'Civil']);
        $general = $this->add(['kind' => 'clause', 'title' => 'Verification form', 'body' => 'I have read the foregoing…']);
        $labor = $this->add(['title' => 'Illegal dismissal', 'practice_area' => 'Labor', 'doctrine' => 'Burden on employer.']);

        $context = app(MatterContext::class)->build($matter);

        $this->assertContains("K{$civil['id']}", $context['sources']);
        $this->assertContains("K{$general['id']}", $context['sources']);
        $this->assertNotContains("K{$labor['id']}", $context['sources']);
        $this->assertStringContainsString('citation="G.R. No. 123456"', $context['text']);
        $this->assertStringContainsString('Doctrine: Employer is solidarily liable.', $context['text']);
    }

    public function test_only_the_author_or_a_managing_partner_removes_an_entry(): void
    {
        $item = $this->add(['title' => 'Mine']);

        $this->signIn(Role::Associate, $this->firm);
        $this->deleteJson("/api/v1/knowledge/{$item['id']}")->assertForbidden();
        $this->putJson("/api/v1/knowledge/{$item['id']}", ['kind' => 'note', 'title' => 'Edited by a colleague'])->assertOk()->assertJsonPath('title', 'Edited by a colleague');

        $this->signIn(Role::ManagingPartner, $this->firm);
        $this->deleteJson("/api/v1/knowledge/{$item['id']}")->assertNoContent();
        $this->assertSoftDeleted('knowledge_items', ['id' => $item['id']]);

        $other = KnowledgeItem::create(['firm_id' => $this->firm->id, 'kind' => 'note', 'title' => 'Firm note']);
        $this->signIn(Role::ManagingPartner, Firm::factory()->create());
        $this->getJson("/api/v1/knowledge/{$other->id}")->assertNotFound();
        $this->assertSame([], $this->getJson('/api/v1/knowledge')->json('data'));
    }
}
