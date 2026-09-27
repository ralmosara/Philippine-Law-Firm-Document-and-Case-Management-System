<?php

namespace Tests\Feature\Documents;

use App\Domain\Documents\Models\DocumentTemplate;
use App\Domain\Documents\Models\DocumentVersion;
use App\Domain\Matters\Models\Client;
use App\Domain\Matters\Models\Firm;
use App\Domain\Matters\Models\Matter;
use App\Enums\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class DocumentApiTest extends TestCase
{
    use RefreshDatabase;

    private Firm $firm;

    private Matter $matter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->firm = Firm::factory()->create(['name' => 'Santos Law']);
        $this->matter = Matter::factory()
            ->for(Client::factory()->for($this->firm)->state(['name' => 'Juan Dela Cruz']))
            ->create(['title' => 'Dela Cruz v. Reyes', 'case_number' => 'CV-2026-0042']);
    }

    public function test_generating_from_a_template_merges_matter_data_and_custom_fields(): void
    {
        $this->signIn(Role::Associate, $this->firm);
        $template = DocumentTemplate::create([
            'firm_id' => $this->firm->id,
            'name' => 'Demand Letter',
            'body' => "Client: {{ client_name }}\nCase: {{case_number}}\nAmount: PHP {{ amount }}\nUnknown: {{ not_provided }}",
        ]);

        $this->assertSame(['client_name', 'case_number', 'amount', 'not_provided'], $template->merge_fields);

        $response = $this->postJson('/api/v1/documents', [
            'matter_id' => $this->matter->id,
            'template_id' => $template->id,
            'fields' => ['amount' => '50,000.00'],
        ])->assertCreated()
            ->assertJsonPath('current_version', 1)
            ->assertJsonPath('status', 'draft');

        $this->assertSame(
            "Client: Juan Dela Cruz\nCase: CV-2026-0042\nAmount: PHP 50,000.00\nUnknown: {{ not_provided }}",
            $response->json('latest_version.content'),
            'Unfilled placeholders stay visible rather than silently becoming blank.',
        );
    }

    public function test_each_save_appends_an_immutable_version(): void
    {
        $this->signIn(Role::Associate, $this->firm);
        $id = $this->postJson('/api/v1/documents', [
            'matter_id' => $this->matter->id, 'title' => 'Answer', 'content' => 'First draft',
        ])->json('id');

        $this->postJson("/api/v1/documents/{$id}/versions", ['content' => 'Second draft', 'change_summary' => 'Added defenses'])
            ->assertCreated()->assertJsonPath('version_number', 2);

        $this->postJson("/api/v1/documents/{$id}/versions", ['content' => 'Second draft'])
            ->assertStatus(422)->assertJsonValidationErrors('content');

        $this->getJson("/api/v1/documents/{$id}/versions")
            ->assertOk()
            ->assertJsonPath('0.version_number', 2)
            ->assertJsonPath('1.content', 'First draft');

        $this->expectException(LogicException::class);
        DocumentVersion::first()->update(['content' => 'Rewritten history']);
    }

    public function test_final_documents_are_frozen(): void
    {
        $this->signIn(Role::Associate, $this->firm);
        $id = $this->postJson('/api/v1/documents', [
            'matter_id' => $this->matter->id, 'title' => 'Answer', 'content' => 'Final text',
        ])->json('id');

        $this->postJson("/api/v1/documents/{$id}/status", ['status' => 'notarized'])->assertStatus(422);
        $this->postJson("/api/v1/documents/{$id}/status", ['status' => 'final'])->assertOk()->assertJsonPath('is_editable', false);

        $this->postJson("/api/v1/documents/{$id}/versions", ['content' => 'Sneaky edit'])
            ->assertStatus(422)->assertJsonValidationErrors('content');
        $this->deleteJson("/api/v1/documents/{$id}")->assertStatus(422);
    }

    public function test_notarial_entries_are_numbered_sequentially_per_notary(): void
    {
        $this->signIn(Role::Partner, $this->firm);
        $entry = fn () => $this->postJson('/api/v1/notarial-entries', [
            'act_type' => 'jurat',
            'document_title' => 'Affidavit of Loss',
            'principal_name' => 'Juan Dela Cruz',
            'competent_evidence' => "Driver's License N01-23-456789",
            'notarized_at' => '2026-03-10 10:00:00',
        ])->assertCreated();

        $entry()->assertJsonPath('doc_number', 1)->assertJsonPath('page_number', 1)->assertJsonPath('series_year', 2026);
        $entry()->assertJsonPath('doc_number', 2);

        $this->getJson('/api/v1/notarial-entries/next?series_year=2026')->assertJsonPath('doc_number', 3);
    }

    public function test_only_lawyers_can_notarize(): void
    {
        $this->signIn(Role::Paralegal, $this->firm);

        $this->postJson('/api/v1/notarial-entries', [
            'act_type' => 'jurat', 'document_title' => 'X', 'principal_name' => 'Y',
            'competent_evidence' => 'Z', 'notarized_at' => '2026-03-10',
        ])->assertForbidden();
    }
}
