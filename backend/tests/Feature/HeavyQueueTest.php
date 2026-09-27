<?php

namespace Tests\Feature;

use App\Domain\Assistant\Jobs\AnswerQuestion;
use App\Domain\Deadlines\Jobs\SendDeadlineReminder;
use App\Domain\Documents\Jobs\ExtractMatterFileText;
use App\Domain\Matters\Models\Client;
use App\Domain\Matters\Models\Firm;
use App\Domain\Matters\Models\Matter;
use App\Enums\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Slow work must never share a queue with deadline reminders. */
class HeavyQueueTest extends TestCase
{
    use RefreshDatabase;

    public function test_ocr_and_ai_jobs_go_to_the_heavy_queue_on_its_own_connection(): void
    {
        config(['queue.heavy' => ['connection' => 'redis-heavy', 'queue' => 'heavy']]);

        foreach ([new ExtractMatterFileText(1), new AnswerQuestion(1)] as $job) {
            $this->assertSame('redis-heavy', $job->connection);
            $this->assertSame('heavy', $job->queue);
        }

        // Reminders stay on the default queue.
        $this->assertNull((new \ReflectionClass(SendDeadlineReminder::class))->newInstanceWithoutConstructor()->queue);
    }

    public function test_an_upload_dispatches_its_text_extraction_to_the_heavy_queue(): void
    {
        Queue::fake();
        Storage::fake('local');
        $firm = Firm::factory()->create();
        $matter = Matter::factory()->for(Client::factory()->for($firm))->create();
        $this->signIn(Role::Associate, $firm);

        $this->postJson("/api/v1/matters/{$matter->id}/files", ['file' => UploadedFile::fake()->create('scan.pdf', 5)])->assertCreated();

        Queue::assertPushedOn('heavy', ExtractMatterFileText::class);
    }

    public function test_the_heavy_connection_outlasts_the_longest_job(): void
    {
        $this->assertGreaterThan((new ExtractMatterFileText(1))->timeout, config('queue.connections.redis-heavy.retry_after'));
    }
}
