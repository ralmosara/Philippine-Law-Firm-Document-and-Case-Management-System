<?php

namespace Tests\Feature\Documents;

use App\Domain\Documents\Compare\TextDiff;
use App\Domain\Documents\Models\MatterFile;
use App\Domain\Matters\Models\Client;
use App\Domain\Matters\Models\Firm;
use App\Domain\Matters\Models\Matter;
use App\Enums\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentCompareTest extends TestCase
{
    use RefreshDatabase;

    private Firm $firm;

    private Matter $matter;

    private int $document;

    protected function setUp(): void
    {
        parent::setUp();
        $this->firm = Firm::factory()->create(['name' => 'Santos Law']);
        $this->matter = Matter::factory()->for(Client::factory()->for($this->firm))->create(['title' => 'Supply agreement']);
        $this->signIn(Role::Associate, $this->firm);

        $this->document = $this->postJson('/api/v1/documents', [
            'matter_id' => $this->matter->id,
            'title' => 'Supply Agreement',
            'content' => "1. The Buyer shall pay within thirty (30) days.\n\n2. Deliveries are made in Makati City.\n\n3. Philippine law governs.",
        ])->json('id');
        $this->postJson("/api/v1/documents/{$this->document}/versions", [
            'content' => "1. The Buyer shall pay within fifteen (15) days.\n\n2. Deliveries are made in Makati City.\n\n3. Philippine law governs.\n\n4. Disputes go to arbitration in Manila.",
            'change_summary' => 'Shorter payment term; arbitration',
        ])->assertCreated();
    }

    public function test_two_versions_are_compared_word_by_word(): void
    {
        $result = $this->getJson("/api/v1/documents/{$this->document}/compare?base=v:1&other=v:2")
            ->assertOk()
            ->assertJsonPath('base.label', 'Version 1')
            ->assertJsonPath('other.label', 'Version 2')
            ->assertJsonPath('deleted_words', 2)
            ->json();

        $changes = collect($result['segments'])->reject(fn ($s) => $s['type'] === 'equal')->values();
        $this->assertSame(['type' => 'delete', 'text' => 'thirty (30)'], $changes[0]);
        $this->assertSame(['type' => 'insert', 'text' => 'fifteen (15)'], $changes[1]);
        $this->assertStringContainsString('Disputes go to arbitration in Manila.', $changes[2]['text']);
        $this->assertSame('insert', $changes[2]['type']);
        $this->assertCount(3, $changes, 'Unchanged clauses stay unmarked.');
    }

    public function test_a_version_is_compared_with_the_other_sides_uploaded_draft(): void
    {
        $file = MatterFile::create([
            'firm_id' => $this->firm->id, 'matter_id' => $this->matter->id, 'original_name' => 'Supply Agreement (seller comments).docx', 'path' => 'x',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'size_bytes' => 1, 'sha256' => str_repeat('0', 64),
        ]);
        // Extracted text: one paragraph per line, no blank lines.
        $file->forceFill(['content_text' => "1. The Buyer shall pay within fifteen (15) days.\n2. Deliveries are made in Taguig City.\n3. Philippine law governs.\n4. Disputes go to arbitration in Manila.", 'text_status' => 'extracted'])->save();

        $this->getJson("/api/v1/documents/{$this->document}/compare/sources")
            ->assertOk()->assertJsonCount(2, 'versions')->assertJsonPath('files.0.value', "f:{$file->id}");

        $result = $this->getJson("/api/v1/documents/{$this->document}/compare?base=v:2&other=f:{$file->id}")->assertOk()->json();
        $changes = collect($result['segments'])->reject(fn ($s) => $s['type'] === 'equal')->values()->all();
        $this->assertSame([['type' => 'delete', 'text' => 'Makati'], ['type' => 'insert', 'text' => 'Taguig']], $changes, 'Blank lines between paragraphs are not changes.');
        $this->assertSame('file', $result['other']['kind']);
    }

    public function test_the_redline_downloads_as_a_pdf(): void
    {
        $response = $this->get("/api/v1/documents/{$this->document}/compare/pdf?base=v:1&other=v:2")->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->assertStringContainsString('supply-agreement-redline.pdf', $response->headers->get('Content-Disposition'));
    }

    public function test_bad_sides_are_explained(): void
    {
        $this->getJson("/api/v1/documents/{$this->document}/compare?base=v:1&other=v:1")->assertStatus(422)->assertJsonValidationErrors('other');
        $this->getJson("/api/v1/documents/{$this->document}/compare?base=v:1&other=v:9")->assertStatus(422)->assertJsonValidationErrors('other');
        $this->getJson("/api/v1/documents/{$this->document}/compare?base=v:1&other=x")->assertStatus(422)->assertJsonValidationErrors('other');

        $scan = MatterFile::create([
            'firm_id' => $this->firm->id, 'matter_id' => $this->matter->id, 'original_name' => 'scan.pdf', 'path' => 'x',
            'mime_type' => 'application/pdf', 'size_bytes' => 1, 'sha256' => str_repeat('1', 64),
        ]);
        $this->getJson("/api/v1/documents/{$this->document}/compare?base=v:1&other=f:{$scan->id}")
            ->assertStatus(422)->assertJsonPath('errors.other.0', 'No text could be read from scan.pdf. Upload it as Word (.docx) or a PDF with selectable text.');

        // A file from another matter cannot be pulled in.
        $elsewhere = Matter::factory()->for(Client::factory()->for($this->firm))->create();
        $foreign = MatterFile::create([
            'firm_id' => $this->firm->id, 'matter_id' => $elsewhere->id, 'original_name' => 'other.docx', 'path' => 'x',
            'mime_type' => 'application/pdf', 'size_bytes' => 1, 'sha256' => str_repeat('2', 64),
        ]);
        $foreign->forceFill(['content_text' => 'Secret', 'text_status' => 'extracted'])->save();
        $this->getJson("/api/v1/documents/{$this->document}/compare?base=v:1&other=f:{$foreign->id}")->assertStatus(422)->assertJsonValidationErrors('other');
    }

    public function test_another_firms_document_is_not_found(): void
    {
        $this->signIn(Role::Associate, Firm::factory()->create());
        $this->getJson("/api/v1/documents/{$this->document}/compare?base=v:1&other=v:2")->assertNotFound();
    }

    public function test_the_diff_always_rebuilds_both_texts(): void
    {
        $diff = new TextDiff;
        mt_srand(11);
        $words = ['the', 'Buyer', 'Seller', 'shall', 'pay', 'not', 'within', 'days', '(30)', ',', '.', "\n", "\n\n"];
        for ($i = 0; $i < 200; $i++) {
            $make = fn () => implode(' ', array_map(fn () => $words[mt_rand(0, count($words) - 1)], range(1, mt_rand(0, 60))));
            $old = $make();
            $new = mt_rand(0, 1) ? $make() : str_replace('pay', 'remit', $old);
            $segments = $diff->compare($old, $new)['segments'];

            $this->assertSame($old, implode('', array_map(fn ($s) => $s['type'] === 'insert' ? '' : $s['text'], $segments)));
            $this->assertSame($new, implode('', array_map(fn ($s) => $s['type'] === 'delete' ? '' : $s['text'], $segments)));
        }
    }
}
