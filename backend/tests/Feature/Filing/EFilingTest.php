<?php

namespace Tests\Feature\Filing;

use App\Domain\Deadlines\Models\MatterDeadline;
use App\Domain\Documents\Actions\CreateDocumentVersion;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\MatterFile;
use App\Domain\Filing\Models\EFiling;
use App\Domain\Matters\Models\Client;
use App\Domain\Matters\Models\Firm;
use App\Domain\Matters\Models\Matter;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EFilingTest extends TestCase
{
    use RefreshDatabase;

    private User $lawyer;

    private Matter $matter;

    private Document $document;

    /** What Ghostscript was asked to do, and the bookmarks it was given. */
    private array $gsRuns = [];

    private string $marks = '';

    private string $numbering = '';

    /** Files pdffonts reports as scans (no text layer). */
    private array $scans = [];

    private int $outputBytes = 50_000;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $firm = Firm::factory()->create(['pleading_paper' => 'folio']);
        $this->lawyer = $this->signIn(Role::Associate, $firm);
        $this->matter = Matter::factory()->for(Client::factory()->for($firm))->create(['firm_id' => $firm->id, 'reference' => 'M-2026-0042', 'title' => 'Santos v. Reyes']);
        $this->document = $this->matter->documents()->create(['firm_id' => $firm->id, 'title' => 'Motion to Dismiss', 'created_by' => $this->lawyer->id]);
        app(CreateDocumentVersion::class)->execute($this->document, "MOTION TO DISMISS\n\nDefendant, by counsel, respectfully states...", $this->lawyer, 'Draft');

        Process::fake(function (PendingProcess $process) {
            $command = $process->command;
            $file = end($command);

            return match ($command[0]) {
                // Separators are one page; everything else two, on 8.5 x 13 in.
                'pdfinfo' => Process::result('Pages:          '.(str_contains($file, 'separator') ? 1 : 2)."\nPage size:      612 x 936 pts\n"),
                'pdffonts' => Process::result(collect($this->scans)->contains(fn ($s) => str_contains($file, $s))
                    ? "name type encoding emb sub uni object ID\n------------------------------------ ----------------- ----------------\n"
                    : "name type encoding emb sub uni object ID\n------------------------------------ ----------------- ----------------\nTimesNewRoman TrueType WinAnsi yes yes no 9 0\n"),
                'gs' => $this->ghostscript($command),
            };
        });
    }

    private function ghostscript(array $command)
    {
        $this->gsRuns[] = $command;
        $output = substr(collect($command)->first(fn ($a) => str_starts_with($a, '-sOutputFile=')), strlen('-sOutputFile='));
        $this->marks = file_get_contents(end($command));
        $this->numbering = file_get_contents(collect($command)->first(fn ($a) => str_ends_with($a, 'numbering.ps')));
        file_put_contents($output, '%PDF-1.6 '.str_repeat('x', $this->outputBytes));

        return Process::result('');
    }

    private function file(string $name, string $mime, string $bytes = '%PDF-1.4 annex', string $description = ''): MatterFile
    {
        $path = "firms/{$this->matter->firm_id}/matters/{$this->matter->id}/".md5($name).'.'.pathinfo($name, PATHINFO_EXTENSION);
        Storage::disk('local')->put($path, $bytes);

        return MatterFile::create([
            'firm_id' => $this->matter->firm_id, 'matter_id' => $this->matter->id, 'original_name' => $name, 'path' => $path,
            'mime_type' => $mime, 'size_bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'description' => $description ?: null,
        ]);
    }

    private function png(): string
    {
        // A real 1x1 PNG, so Dompdf can place it on a page.
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    }

    public function test_a_pleading_and_its_annexes_become_one_numbered_bookmarked_pdf(): void
    {
        $contract = $this->file('contract.pdf', 'application/pdf', '%PDF-1.4 contract', 'Deed of Absolute Sale');
        $photo = $this->file('photo.png', 'image/png', $this->png(), 'Photo of the property');
        $this->scans = ['01.pdf']; // Annex "A" is a scan without a text layer

        $res = $this->postJson("/api/v1/matters/{$this->matter->id}/e-filings", [
            'document_id' => $this->document->id, 'annexes' => [['file_id' => $contract->id, 'description' => 'Deed of Absolute Sale'], ['file_id' => $photo->id, 'description' => 'Photo of the property']],
        ])->assertCreated()
            ->assertJsonPath('status', 'prepared')
            ->assertJsonPath('page_count', 8) // pleading 2, separator 1, annex 2, separator 1, image 2
            ->assertJsonPath('items.1.label', 'Annex "A"')
            ->assertJsonPath('items.1.first_page', 4)
            ->assertJsonPath('items.2.label', 'Annex "B"');

        // One Ghostscript run: numbering first, the parts in order, bookmarks last; safe mode.
        $gs = $this->gsRuns[0];
        $this->assertContains('-dSAFER', $gs);
        $this->assertStringEndsWith('numbering.ps', $gs[array_search('-sOutputFile='.substr(collect($gs)->first(fn ($a) => str_starts_with($a, '-sOutputFile=')), 13), $gs) + 1]);
        $this->assertStringEndsWith('marks.ps', end($gs));
        $this->assertStringContainsString('( of 8)', $this->numbering);
        $this->assertStringContainsString('/Page 4 /OUT pdfmark', $this->marks);   // Annex "A" starts on page 4
        $this->assertStringContainsString('/Page 7 /OUT pdfmark', $this->marks);   // Annex "B" on page 7
        $this->assertStringContainsString(strtoupper(bin2hex(mb_convert_encoding('Annex "A" – Deed of Absolute Sale', 'UTF-16BE', 'UTF-8'))), $this->marks);

        $checks = collect($res->json('checks'));
        $this->assertSame('ok', $checks[0]['level']);
        $this->assertTrue($checks->contains(fn ($c) => str_contains($c['message'], 'still a draft')));
        $this->assertTrue($checks->contains(fn ($c) => str_contains($c['message'], 'Annex "A" (contract.pdf) is a scan')));

        // Saved with the matter's files.
        $package = MatterFile::findOrFail($res->json('package.id'));
        $this->assertSame('application/pdf', $package->mime_type);
        $this->assertStringContainsString('e-filing', $package->original_name);
    }

    public function test_an_oversized_package_is_retried_smaller_then_flagged(): void
    {
        config(['services.efiling.max_mb' => 1]);
        $this->outputBytes = 2 * 1024 * 1024;
        $scan = $this->file('scan.pdf', 'application/pdf');

        $res = $this->postJson("/api/v1/matters/{$this->matter->id}/e-filings", ['document_id' => $this->document->id, 'annexes' => [['file_id' => $scan->id]], 'annex_style' => 'numbers', 'separators' => false])
            ->assertCreated()
            ->assertJsonPath('items.1.label', 'Annex "1"')
            ->assertJsonPath('page_count', 4);

        $this->assertCount(2, $this->gsRuns);
        $this->assertContains('-dPDFSETTINGS=/printer', $this->gsRuns[0]);
        $this->assertContains('-dPDFSETTINGS=/ebook', $this->gsRuns[1]);
        $levels = collect($res->json('checks'))->pluck('level');
        $this->assertContains('error', $levels);
    }

    public function test_filing_and_acknowledgment_are_recorded_and_the_deadline_is_met(): void
    {
        $deadline = MatterDeadline::create(['firm_id' => $this->matter->firm_id, 'matter_id' => $this->matter->id, 'kind' => 'filing', 'title' => 'File motion to dismiss', 'due_date' => now()->addDays(3)->toDateString()]);
        $id = $this->postJson("/api/v1/matters/{$this->matter->id}/e-filings", ['document_id' => $this->document->id, 'annexes' => []])->assertCreated()->json('id');

        $this->postJson("/api/v1/e-filings/{$id}/acknowledged", ['acknowledged_at' => now()->toIso8601String()])->assertStatus(422); // not filed yet
        $this->postJson("/api/v1/e-filings/{$id}/filed", [
            'filed_at' => now()->toIso8601String(), 'filed_via' => 'email', 'filed_to' => 'rtc143makati@judiciary.gov.ph', 'filing_reference' => 'Sent 9:12 AM', 'deadline_id' => $deadline->id,
        ])->assertOk()->assertJsonPath('status', 'filed')->assertJsonPath('filed_by', $this->lawyer->name);
        $this->assertSame('completed', $deadline->fresh()->status->value);

        $receipt = $this->file('ack.pdf', 'application/pdf');
        $this->postJson("/api/v1/e-filings/{$id}/acknowledged", ['acknowledged_at' => now()->toIso8601String(), 'acknowledgment' => 'Received, OCC ref 2026-1188', 'acknowledgment_file_id' => $receipt->id])
            ->assertOk()->assertJsonPath('status', 'acknowledged')->assertJsonPath('acknowledgment_file.name', 'ack.pdf');

        $this->assertCount(1, $this->getJson("/api/v1/matters/{$this->matter->id}/e-filings")->json('data'));
    }

    public function test_the_signed_pdf_can_be_the_pleading(): void
    {
        $signed = $this->file('Motion to Dismiss (signed).pdf', 'application/pdf');
        $annex = $this->file('receipt.pdf', 'application/pdf');

        $this->postJson("/api/v1/matters/{$this->matter->id}/e-filings", ['main_file_id' => $signed->id, 'annexes' => [['file_id' => $annex->id]]])
            ->assertCreated()
            ->assertJsonPath('items.0.label', 'Pleading')
            ->assertJsonPath('items.0.name', 'Motion to Dismiss (signed).pdf')
            ->assertJsonPath('title', 'Motion to Dismiss (signed)');

        // One pleading or the other, not both.
        $this->postJson("/api/v1/matters/{$this->matter->id}/e-filings", ['main_file_id' => $signed->id, 'document_id' => $this->document->id, 'annexes' => []])
            ->assertStatus(422)->assertJsonValidationErrors('main_file_id');
    }

    public function test_only_pdfs_and_images_from_the_same_matter_are_accepted(): void
    {
        $docx = $this->file('draft.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'PK...');
        $this->postJson("/api/v1/matters/{$this->matter->id}/e-filings", ['document_id' => $this->document->id, 'annexes' => [['file_id' => $docx->id]]])
            ->assertStatus(422)->assertJsonValidationErrors('annexes');

        $other = Matter::factory()->create(['firm_id' => $this->matter->firm_id]);
        $foreign = MatterFile::create(['firm_id' => $this->matter->firm_id, 'matter_id' => $other->id, 'original_name' => 'x.pdf', 'path' => 'x', 'mime_type' => 'application/pdf', 'size_bytes' => 1, 'sha256' => str_repeat('a', 64)]);
        $this->postJson("/api/v1/matters/{$this->matter->id}/e-filings", ['document_id' => $this->document->id, 'annexes' => [['file_id' => $foreign->id]]])->assertStatus(422);
        $this->assertSame(0, EFiling::count());

        // Staff cannot prepare filings; other firms cannot see them.
        $this->signIn(Role::Staff, Firm::find($this->matter->firm_id));
        $this->postJson("/api/v1/matters/{$this->matter->id}/e-filings", ['document_id' => $this->document->id, 'annexes' => []])->assertForbidden();
        $this->signIn(Role::ManagingPartner, Firm::factory()->create());
        $this->getJson("/api/v1/matters/{$this->matter->id}/e-filings")->assertNotFound();
    }
}
