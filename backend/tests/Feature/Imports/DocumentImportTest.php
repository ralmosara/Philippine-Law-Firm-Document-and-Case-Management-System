<?php

namespace Tests\Feature\Imports;

use App\Domain\Documents\Models\MatterFile;
use App\Domain\Imports\Models\DocumentImport;
use App\Domain\Matters\Models\Client;
use App\Domain\Matters\Models\Firm;
use App\Domain\Matters\Models\Matter;
use App\Enums\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class DocumentImportTest extends TestCase
{
    use RefreshDatabase;

    private Firm $firm;

    private Matter $kalayaan;

    private Matter $reyes;

    private string $zip;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->firm = Firm::factory()->create();
        $this->signIn(Role::ManagingPartner, $this->firm);
        $client = Client::factory()->for($this->firm)->create();
        $this->kalayaan = Matter::factory()->for($client)->create(['reference' => 'M-2025-0042', 'case_number' => 'R-MKT-25-0042']);
        $this->reyes = Matter::factory()->for($client)->create(['reference' => 'M-2025-0050', 'case_number' => 'CV-2025-0050']);
        $this->zip = $this->makeZip([
            'M-2025-0042 Kalayaan Realty/Pleadings/Complaint.txt' => 'COMPLAINT for sum of money, filed in the Regional Trial Court.',
            'M-2025-0042 Kalayaan Realty/demand letter.txt' => 'Demand letter dated March 1, 2025.',
            'M-2025-0042 Kalayaan Realty/setup.exe' => 'MZ not a document',
            'CV-2025-0050/answer.txt' => 'ANSWER with counterclaim.',
            'Old cases 2019/memo.txt' => 'Research memorandum on prescription.',
            'loose.txt' => 'A file outside any folder.',
            '__MACOSX/M-2025-0042 Kalayaan Realty/._Complaint.txt' => 'junk',
            'M-2025-0042 Kalayaan Realty/.DS_Store' => 'junk',
            'M-2025-0042 Kalayaan Realty/empty.txt' => '',
        ]);
    }

    private function makeZip(array $files): string
    {
        $path = tempnam(sys_get_temp_dir(), 'zip').'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        return $path;
    }

    /** Upload in two pieces, as the browser does with larger archives. */
    private function upload(string $path): int
    {
        $bytes = (string) file_get_contents($path);
        $id = $this->postJson('/api/v1/document-imports', ['filename' => 'old-files.zip', 'size' => strlen($bytes)])->assertCreated()->assertJsonPath('status', 'uploading')->json('id');
        $half = intdiv(strlen($bytes), 2);
        foreach ([substr($bytes, 0, $half), substr($bytes, $half)] as $i => $piece) {
            $this->post("/api/v1/document-imports/{$id}/chunks/{$i}", ['chunk' => UploadedFile::fake()->createWithContent("part{$i}", $piece)], ['Accept' => 'application/json'])->assertOk();
        }

        return $id;
    }

    public function test_a_zip_is_previewed_folder_by_folder_then_filed(): void
    {
        $id = $this->upload($this->zip);
        $preview = $this->postJson("/api/v1/document-imports/{$id}/finish")->assertOk()->assertJsonPath('status', 'previewed')->json('folders');
        $folders = collect($preview)->keyBy('folder');

        $this->assertSame(['', 'CV-2025-0050', 'M-2025-0042 Kalayaan Realty', 'Old cases 2019'], $folders->keys()->sort()->values()->all(), 'Junk (__MACOSX, .DS_Store) is left out.');
        $this->assertSame('M-2025-0042', $folders['M-2025-0042 Kalayaan Realty']['matter']['reference'], 'Matched by the reference at the start of the name.');
        $this->assertSame('M-2025-0050', $folders['CV-2025-0050']['matter']['reference'], 'Matched by case number.');
        $this->assertNull($folders['Old cases 2019']['matter']);
        $files = collect($folders['M-2025-0042 Kalayaan Realty']['files'])->keyBy('name');
        $this->assertSame('Pleadings', $files['Complaint.txt']['path']);
        $this->assertSame('This type of file is not accepted.', $files['setup.exe']['reason']);
        $this->assertSame('The file is empty.', $files['empty.txt']['reason']);

        // An unmatched folder is filed by hand; the loose file stays out.
        $this->postJson("/api/v1/document-imports/{$id}/assign", ['folder' => 'Old cases 2019', 'matter_id' => $this->reyes->id])->assertOk();

        $this->postJson("/api/v1/document-imports/{$id}/commit")->assertOk(); // sync queue: runs now
        $import = $this->getJson("/api/v1/document-imports/{$id}")->assertOk()->assertJsonPath('status', 'done')->json();
        $this->assertSame(['imported' => 4, 'skipped' => 3, 'failed' => 0, 'remaining' => 0], $import['summary']);

        $complaint = MatterFile::where('original_name', 'Complaint.txt')->firstOrFail();
        $this->assertSame($this->kalayaan->id, $complaint->matter_id);
        $this->assertSame('Imported: Pleadings', $complaint->description);
        $this->assertSame(2, MatterFile::where('matter_id', $this->reyes->id)->count());
        $this->assertFalse(Storage::disk('local')->exists("imports/firm-{$this->firm->id}/document-import-{$id}.zip"), 'The ZIP is deleted once filed.');
    }

    public function test_files_already_in_the_matter_are_skipped_and_undo_takes_back_the_rest(): void
    {
        $id = $this->upload($this->zip);
        $this->postJson("/api/v1/document-imports/{$id}/finish")->assertOk();
        $this->postJson("/api/v1/document-imports/{$id}/commit")->assertOk();
        $this->assertSame(3, MatterFile::count());

        // The same archive again: everything is already filed.
        $again = $this->upload($this->zip);
        $this->postJson("/api/v1/document-imports/{$again}/finish")->assertOk();
        $this->postJson("/api/v1/document-imports/{$again}/commit")->assertOk();
        $this->getJson("/api/v1/document-imports/{$again}")->assertJsonPath('summary.imported', 0);
        $this->assertSame(3, MatterFile::count());

        // Undo the first import, except a file shared with the client since.
        MatterFile::where('original_name', 'answer.txt')->update(['shared_with_client' => true]);
        $result = $this->postJson("/api/v1/document-imports/{$id}/undo")->assertOk();
        $this->assertSame(2, $result->json('undone'));
        $this->assertStringContainsString('answer.txt: kept, shared with the client', $result->json('kept.0'));
        $this->assertSame(1, MatterFile::count());
        $this->postJson("/api/v1/document-imports/{$id}/undo")->assertStatus(422);
    }

    public function test_pieces_must_arrive_in_order_and_a_retry_is_harmless(): void
    {
        $bytes = (string) file_get_contents($this->zip);
        $id = $this->postJson('/api/v1/document-imports', ['filename' => 'old-files.zip', 'size' => strlen($bytes)])->json('id');
        $piece = fn (string $content) => ['chunk' => UploadedFile::fake()->createWithContent('part', $content)];
        $half = intdiv(strlen($bytes), 2);

        $this->post("/api/v1/document-imports/{$id}/chunks/1", $piece(substr($bytes, $half)), ['Accept' => 'application/json'])->assertStatus(422);
        $this->post("/api/v1/document-imports/{$id}/chunks/0", $piece(substr($bytes, 0, $half)), ['Accept' => 'application/json'])->assertJsonPath('received_chunks', 1);
        $this->post("/api/v1/document-imports/{$id}/chunks/0", $piece(substr($bytes, 0, $half)), ['Accept' => 'application/json'])->assertJsonPath('received_chunks', 1);
        $this->postJson("/api/v1/document-imports/{$id}/finish")->assertStatus(422); // not all there yet
        $this->post("/api/v1/document-imports/{$id}/chunks/1", $piece(substr($bytes, $half)), ['Accept' => 'application/json'])->assertJsonPath('received_bytes', strlen($bytes));
        $this->postJson("/api/v1/document-imports/{$id}/finish")->assertOk();
    }

    public function test_bad_archives_are_refused(): void
    {
        $this->postJson('/api/v1/document-imports', ['filename' => 'files.rar', 'size' => 1000])->assertStatus(422);
        $this->postJson('/api/v1/document-imports', ['filename' => 'huge.zip', 'size' => 50 * 1024 * 1024 * 1024])->assertStatus(422);

        $notZip = tempnam(sys_get_temp_dir(), 'nz');
        file_put_contents($notZip, str_repeat('not a zip ', 10));
        $id = $this->upload($notZip);
        $this->postJson("/api/v1/document-imports/{$id}/finish")->assertStatus(422)->assertJsonPath('errors.file.0', 'This is not a ZIP file we can read.');
        $this->assertSame(DocumentImport::FAILED, DocumentImport::find($id)->status);

        config(['services.document_imports.max_files' => 3]);
        $id = $this->upload($this->zip);
        $this->postJson("/api/v1/document-imports/{$id}/finish")->assertStatus(422)->assertJsonPath('errors.file.0', 'The ZIP has more than 3 files; split it into several.');
    }

    public function test_only_firm_managers_import_documents_and_other_firms_see_nothing(): void
    {
        $id = $this->upload($this->zip);
        $this->signIn(Role::Associate, $this->firm);
        $this->getJson('/api/v1/document-imports')->assertForbidden();

        $this->signIn(Role::ManagingPartner, Firm::factory()->create());
        $this->getJson("/api/v1/document-imports/{$id}")->assertNotFound();
    }
}
