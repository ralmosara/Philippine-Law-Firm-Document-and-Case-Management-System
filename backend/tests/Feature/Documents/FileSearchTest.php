<?php

namespace Tests\Feature\Documents;

use App\Domain\Documents\Models\MatterFile;
use App\Domain\Documents\Search\FileTextExtractor;
use App\Domain\Matters\Models\Client;
use App\Domain\Matters\Models\Firm;
use App\Domain\Matters\Models\Matter;
use App\Enums\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class FileSearchTest extends TestCase
{
    use RefreshDatabase;

    private Firm $firm;

    private Matter $matter;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->firm = Firm::factory()->create();
        $this->matter = Matter::factory()->for(Client::factory()->for($this->firm))->create();
        $this->signIn(Role::Associate, $this->firm);
    }

    public function test_text_is_extracted_from_pdf_word_and_plain_text_files(): void
    {
        $pdf = $this->upload('deed.pdf', $this->pdf('Deed of Absolute Sale covering TCT No. 12345 in Quezon City'));
        $docx = $this->upload('contract.docx', $this->docx(['Lease Contract', 'The lessee shall pay the monthly rental of PHP 45,000.']));
        $txt = $this->upload('notes.txt', "Call with witness Ramon Bautista\nRe: demand letter");
        $image = $this->upload('photo.png', UploadedFile::fake()->image('photo.png')->getContent());

        $this->assertStringContainsString('Deed of Absolute Sale', MatterFile::find($pdf)->content_text);
        $this->assertStringContainsString("Lease Contract\nThe lessee shall pay", MatterFile::find($docx)->content_text);
        $this->assertSame('extracted', MatterFile::find($txt)->text_status);
        $this->assertSame('unsupported', MatterFile::find($image)->text_status);
    }

    public function test_search_finds_words_inside_files_with_a_highlighted_snippet(): void
    {
        $this->upload('deed.pdf', $this->pdf('Deed of Absolute Sale covering TCT No. 12345 in Quezon City'));
        $this->upload('notes.txt', 'Call with witness Ramon Bautista about the demand letter');

        $this->getJson('/api/v1/files?search=absolute sale')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'deed.pdf')
            ->assertJsonPath('data.0.matter.id', $this->matter->id)
            ->assertJsonMissingPath('data.0.content_text')
            ->assertJsonPath('data.0.snippet', fn (string $snippet) => str_contains($snippet, '⟦Absolute⟧') && str_contains($snippet, '⟦Sale⟧'));

        // Every word must match; a partial word matches its prefix.
        $this->getJson('/api/v1/files?search=bautis')->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'notes.txt');
        $this->getJson('/api/v1/files?search=bautista sale')->assertJsonCount(0, 'data');
        // Names count too.
        $this->getJson('/api/v1/files?search=deed')->assertJsonCount(1, 'data');
    }

    public function test_search_stays_inside_the_firm_and_can_be_limited_to_a_matter(): void
    {
        $this->upload('deed.pdf', $this->pdf('Confidential settlement terms'));
        $otherMatter = Matter::factory()->for(Client::factory()->for($this->firm))->create();
        $this->postJson("/api/v1/matters/{$otherMatter->id}/files", ['file' => UploadedFile::fake()->createWithContent('other.txt', 'settlement draft')]);

        $this->getJson('/api/v1/files?search=settlement')->assertJsonCount(2, 'data');
        $this->getJson("/api/v1/files?search=settlement&matter_id={$this->matter->id}")->assertJsonCount(1, 'data');

        $this->signIn(Role::ManagingPartner); // another firm
        $this->getJson('/api/v1/files?search=settlement')->assertJsonCount(0, 'data');
    }

    public function test_removed_files_are_not_found(): void
    {
        $id = $this->upload('notes.txt', 'privileged strategy memo');
        $this->deleteJson("/api/v1/files/{$id}")->assertNoContent();

        $this->getJson('/api/v1/files?search=strategy')->assertJsonCount(0, 'data');
    }

    public function test_the_extractor_reads_spreadsheets_and_normalises_legacy_encodings(): void
    {
        $extractor = new FileTextExtractor;

        $xlsx = tempnam(sys_get_temp_dir(), 'x');
        $zip = new ZipArchive;
        $zip->open($xlsx, ZipArchive::OVERWRITE);
        $zip->addFromString('xl/sharedStrings.xml', '<sst><si><t>Unpaid rentals</t></si><si><t>Barangay San Roque</t></si></sst>');
        $zip->close();
        $this->assertSame("Unpaid rentals\nBarangay San Roque", $extractor->extract($xlsx, 'xlsx'));

        $txt = tempnam(sys_get_temp_dir(), 't');
        file_put_contents($txt, "Pe\xF1a v. Ca\xF1ete"); // Windows-1252 ñ
        $this->assertSame('Peña v. Cañete', $extractor->extract($txt, 'txt'));

        unlink($xlsx);
        unlink($txt);
    }

    private function upload(string $name, string $content): int
    {
        return $this->postJson("/api/v1/matters/{$this->matter->id}/files", ['file' => UploadedFile::fake()->createWithContent($name, $content)])
            ->assertCreated()
            ->json('id');
    }

    /** A minimal, valid single-page PDF containing the given line of text. */
    private function pdf(string $text): string
    {
        $stream = 'BT /F1 12 Tf 72 720 Td ('.addcslashes($text, '()\\').') Tj ET';
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
            '<< /Length '.strlen($stream)." >>\nstream\n{$stream}\nendstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $i => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1)." 0 obj\n{$object}\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= 'xref'."\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf.'trailer << /Size '.(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
    }

    /** @param  list<string>  $paragraphs */
    private function docx(array $paragraphs): string
    {
        $body = implode('', array_map(fn ($p) => '<w:p><w:r><w:t>'.htmlspecialchars($p).'</w:t></w:r></w:p>', $paragraphs));
        $path = tempnam(sys_get_temp_dir(), 'd');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
        $zip->addFromString('word/document.xml', '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'.$body.'</w:body></w:document>');
        $zip->close();
        $content = (string) file_get_contents($path);
        unlink($path);

        return $content;
    }
}
