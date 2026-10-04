<?php

namespace Tests\Feature\Documents;

use App\Domain\Documents\Jobs\ExtractMatterFileText;
use App\Domain\Documents\Models\MatterFile;
use App\Domain\Matters\Models\Client;
use App\Domain\Matters\Models\Firm;
use App\Domain\Matters\Models\Matter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ReextractFilesTest extends TestCase
{
    use RefreshDatabase;

    public function test_files_that_could_not_be_read_before_are_queued_once_their_format_is_supported(): void
    {
        Queue::fake();
        config(['services.text_extraction.tools' => ['catdoc' => true, 'msgconvert' => true, 'xls2csv' => false, 'catppt' => false]]);
        $matter = Matter::factory()->for(Client::factory()->for(Firm::factory()))->create();
        $make = fn (string $name, string $status) => tap(MatterFile::withoutGlobalScopes()->create([
            'firm_id' => $matter->firm_id, 'matter_id' => $matter->id, 'original_name' => $name, 'path' => "x/{$name}",
            'mime_type' => 'application/octet-stream', 'size_bytes' => 1, 'sha256' => hash('sha256', $name),
        ]), fn (MatterFile $file) => $file->forceFill(['text_status' => $status])->save());

        $doc = $make('old-deed.DOC', 'unsupported');
        $msg = $make('demand.msg', 'failed');
        $xls = $make('ledger.xls', 'unsupported');   // no tool: stays as it is
        $mp3 = $make('hearing.mp3', 'unsupported');  // never readable

        $this->artisan('files:extract-text')->expectsOutput('Queued 1 file(s) for text extraction.')->assertSuccessful();
        Queue::assertPushed(ExtractMatterFileText::class, fn ($job) => $job->fileId === $doc->id);
        $this->assertSame('pending', $doc->fresh()->text_status);

        $this->artisan('files:extract-text --failed')->expectsOutput('Queued 1 file(s) for text extraction.')->assertSuccessful();
        Queue::assertPushed(ExtractMatterFileText::class, fn ($job) => $job->fileId === $msg->id);
        $this->assertSame('unsupported', $xls->fresh()->text_status);
        $this->assertSame('unsupported', $mp3->fresh()->text_status);
    }
}
