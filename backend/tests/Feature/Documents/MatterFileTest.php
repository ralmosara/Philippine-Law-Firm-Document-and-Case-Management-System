<?php

namespace Tests\Feature\Documents;

use App\Domain\Documents\Models\MatterFile;
use App\Domain\Matters\Models\Client;
use App\Domain\Matters\Models\Firm;
use App\Domain\Matters\Models\Matter;
use App\Enums\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MatterFileTest extends TestCase
{
    use RefreshDatabase;

    private Firm $firm;

    private Client $client;

    private Matter $matter;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->firm = Firm::factory()->create();
        $this->client = Client::factory()->for($this->firm)->withPortal('secret-pass')->create();
        $this->matter = Matter::factory()->for($this->client)->create();
    }

    public function test_files_are_stored_privately_with_a_checksum_and_downloaded_as_attachments(): void
    {
        $user = $this->signIn(Role::Paralegal, $this->firm);
        $upload = UploadedFile::fake()->createWithContent('Judicial Affidavit.pdf', '%PDF-1.7 test evidence');

        $id = $this->post("/api/v1/matters/{$this->matter->id}/files", ['file' => $upload, 'description' => 'Signed JA'], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('name', 'Judicial Affidavit.pdf')
            ->assertJsonPath('sha256', hash('sha256', '%PDF-1.7 test evidence'))
            ->assertJsonPath('uploader.id', $user->id)
            ->assertJsonPath('shared_with_client', false)
            ->assertJsonMissingPath('path')
            ->json('id');

        $file = MatterFile::find($id);
        $this->assertStringStartsWith("firms/{$this->firm->id}/matters/{$this->matter->id}/", $file->path);
        $this->assertStringNotContainsString('Judicial', $file->path);
        Storage::disk('local')->assertExists($file->path);

        $download = $this->get("/api/v1/files/{$id}/download")->assertOk();
        $this->assertStringStartsWith('attachment;', $download->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $download->headers->get('X-Content-Type-Options'));
        $this->assertSame('%PDF-1.7 test evidence', $download->streamedContent());

        $this->getJson("/api/v1/matters/{$this->matter->id}/files")->assertJsonCount(1);
    }

    public function test_dangerous_and_oversized_files_are_rejected(): void
    {
        $this->signIn(Role::Associate, $this->firm);

        foreach ([
            UploadedFile::fake()->createWithContent('page.html', '<script>alert(1)</script>'),
            UploadedFile::fake()->create('run.exe', 10, 'application/x-msdownload'),
            UploadedFile::fake()->create('huge.pdf', MatterFile::MAX_KILOBYTES + 1, 'application/pdf'),
        ] as $upload) {
            $this->postJson("/api/v1/matters/{$this->matter->id}/files", ['file' => $upload])
                ->assertStatus(422)->assertJsonValidationErrors('file');
        }

        $this->assertDatabaseCount('matter_files', 0);
    }

    public function test_deleting_hides_the_file_but_keeps_it_for_retention(): void
    {
        $this->signIn(Role::Associate, $this->firm);
        $id = $this->postJson("/api/v1/matters/{$this->matter->id}/files", ['file' => UploadedFile::fake()->create('memo.docx', 5)])->json('id');
        $path = MatterFile::find($id)->path;

        $this->deleteJson("/api/v1/files/{$id}")->assertNoContent();

        $this->getJson("/api/v1/matters/{$this->matter->id}/files")->assertJsonCount(0);
        $this->get("/api/v1/files/{$id}/download")->assertNotFound();
        Storage::disk('local')->assertExists($path);
    }

    public function test_other_firms_cannot_see_or_download_files(): void
    {
        $this->signIn(Role::Associate, $this->firm);
        $id = $this->postJson("/api/v1/matters/{$this->matter->id}/files", ['file' => UploadedFile::fake()->create('secret.pdf', 5)])->json('id');

        $this->signIn(Role::ManagingPartner);
        $this->get("/api/v1/files/{$id}/download")->assertNotFound();
        $this->getJson("/api/v1/matters/{$this->matter->id}/files")->assertNotFound();
        $this->postJson("/api/v1/matters/{$this->matter->id}/files", ['file' => UploadedFile::fake()->create('x.pdf', 5)])->assertNotFound();
    }

    public function test_clients_download_only_files_shared_with_them(): void
    {
        $this->signIn(Role::Associate, $this->firm);
        $shared = $this->postJson("/api/v1/matters/{$this->matter->id}/files", ['file' => UploadedFile::fake()->create('order.pdf', 5), 'shared_with_client' => true])->json('id');
        $internal = $this->postJson("/api/v1/matters/{$this->matter->id}/files", ['file' => UploadedFile::fake()->create('notes.pdf', 5)])->json('id');

        $otherClient = Client::factory()->for($this->firm)->withPortal('x')->create();
        $otherFile = MatterFile::create([
            'firm_id' => $this->firm->id, 'matter_id' => Matter::factory()->for($otherClient)->create()->id,
            'original_name' => 'theirs.pdf', 'path' => 'x', 'mime_type' => 'application/pdf', 'size_bytes' => 1,
            'sha256' => str_repeat('0', 64), 'shared_with_client' => true,
        ]);

        $this->actingAs($this->client, 'client');

        $this->getJson("/api/portal/matters/{$this->matter->id}")
            ->assertJsonCount(1, 'files')
            ->assertJsonPath('files.0.name', 'order.pdf');
        $this->get("/api/portal/files/{$shared}/download")->assertOk();
        $this->get("/api/portal/files/{$internal}/download")->assertNotFound();
        $this->get("/api/portal/files/{$otherFile->id}/download")->assertNotFound();
    }

    public function test_staff_without_matter_rights_cannot_upload(): void
    {
        $this->signIn(Role::Staff, $this->firm);

        $this->postJson("/api/v1/matters/{$this->matter->id}/files", ['file' => UploadedFile::fake()->create('x.pdf', 5)])
            ->assertForbidden();
    }
}
