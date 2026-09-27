<?php

namespace Tests\Feature\Documents;

use App\Domain\Documents\Models\MatterFile;
use App\Domain\Matters\Models\Client;
use App\Domain\Matters\Models\Firm;
use App\Domain\Matters\Models\Matter;
use App\Enums\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OcrTest extends TestCase
{
    use RefreshDatabase;

    private Matter $matter;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['services.ocr.enabled' => true, 'services.ocr.languages' => 'eng+fil']);
        $firm = Firm::factory()->create();
        $this->matter = Matter::factory()->for(Client::factory()->for($firm))->create();
        $this->signIn(Role::Associate, $firm);
    }

    public function test_scanned_images_are_read_with_tesseract_and_become_searchable(): void
    {
        Process::fake(['*' => Process::result("SINUMPAANG SALAYSAY\nAko si Juan Dela Cruz, nasa hustong gulang\n")]);

        $id = $this->upload('salaysay.jpg', UploadedFile::fake()->image('salaysay.jpg')->getContent());

        $file = MatterFile::find($id);
        $this->assertSame('ocr', $file->text_source);
        $this->assertStringContainsString('SINUMPAANG SALAYSAY', $file->content_text);
        Process::assertRan(fn (PendingProcess $p) => $p->command[0] === 'tesseract' && in_array('eng+fil', $p->command, true));

        $this->getJson('/api/v1/files?search=salaysay dela cruz')->assertJsonCount(1, 'data')->assertJsonPath('data.0.text_source', 'ocr');
    }

    public function test_image_only_pdfs_are_rasterised_page_by_page(): void
    {
        Process::fake(function (PendingProcess $process) {
            if ($process->command[0] === 'pdftoppm') {
                $prefix = end($process->command);
                file_put_contents("{$prefix}-1.png", 'x');
                file_put_contents("{$prefix}-2.png", 'x');

                return Process::result();
            }

            return Process::result(str_contains($process->command[1], 'page-1') ? 'Page one: Deed of Sale' : 'Page two: Notarial acknowledgment');
        });

        $id = $this->upload('scan.pdf', "%PDF-1.4\n%%EOF"); // no text layer

        $text = MatterFile::find($id)->content_text;
        $this->assertStringContainsString('Page one: Deed of Sale', $text);
        $this->assertStringContainsString('Page two: Notarial acknowledgment', $text);
        $this->assertSame([], glob(storage_path('app/ocr-*')) ?: []); // temp pages cleaned up
    }

    public function test_without_ocr_images_are_not_searchable_and_nothing_is_run(): void
    {
        config(['services.ocr.enabled' => false]);
        Process::fake();

        $id = $this->upload('photo.png', UploadedFile::fake()->image('photo.png')->getContent());

        $this->assertSame('unsupported', MatterFile::find($id)->text_status);
        Process::assertNothingRan();
    }

    public function test_an_ocr_failure_marks_the_file_but_keeps_it(): void
    {
        Process::fake(['*' => Process::result(errorOutput: 'Error opening data file', exitCode: 1)]);

        $id = $this->upload('bad.png', UploadedFile::fake()->image('bad.png')->getContent());

        $this->assertSame('failed', MatterFile::find($id)->text_status);
        $this->assertNotNull(MatterFile::find($id)->path);
    }

    private function upload(string $name, string $content): int
    {
        return $this->postJson("/api/v1/matters/{$this->matter->id}/files", ['file' => UploadedFile::fake()->createWithContent($name, $content)])
            ->assertCreated()->json('id');
    }
}
