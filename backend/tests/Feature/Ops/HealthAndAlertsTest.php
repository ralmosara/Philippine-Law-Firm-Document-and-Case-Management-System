<?php

namespace Tests\Feature\Ops;

use App\Jobs\QueueHeartbeat;
use App\Support\Ops\SystemHealth;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class HealthAndAlertsTest extends TestCase
{
    use RefreshDatabase;

    private ?string $statusFile = null;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mail.default' => 'array', 'services.clamav.enabled' => false, 'ops.alert_email' => null]);
    }

    protected function tearDown(): void
    {
        if ($this->statusFile && is_file($this->statusFile)) {
            unlink($this->statusFile);
        }
        parent::tearDown();
    }

    /** @return list<Email> */
    private function sentMail(): array
    {
        return array_map(fn ($m) => $m->getOriginalMessage(), iterator_to_array(app('mail.manager')->mailer('array')->getSymfonyTransport()->messages()));
    }

    private function backupStatus(array $status): void
    {
        $this->statusFile = tempnam(sys_get_temp_dir(), 'backup-status');
        file_put_contents($this->statusFile, json_encode($status));
        config(['ops.backup_status_file' => $this->statusFile]);
    }

    public function test_a_healthy_system_answers_200_without_details_for_the_public(): void
    {
        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('checks.database', 'ok')
            ->assertJsonPath('checks.cache', 'ok')
            ->assertJsonMissingPath('checks.database.message')
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_details_need_the_health_token(): void
    {
        config(['ops.health_token' => 'secret-token']);

        $this->getJson('/api/health', ['Authorization' => 'Bearer wrong'])->assertJsonPath('checks.database', 'ok');
        $this->getJson('/api/health', ['Authorization' => 'Bearer secret-token'])
            ->assertJsonPath('checks.database.status', 'ok')
            ->assertJsonPath('checks.database.message', 'Connected');
    }

    public function test_a_stopped_worker_or_scheduler_fails_the_check_until_it_reports_in(): void
    {
        config(['ops.expect_workers' => true]);

        $this->getJson('/api/health')
            ->assertStatus(503)
            ->assertJsonPath('status', 'failing')
            ->assertJsonPath('checks.scheduler', 'failing')
            ->assertJsonPath('checks.queue_default', 'failing')
            ->assertJsonPath('checks.queue_heavy', 'failing');

        Cache::put(SystemHealth::SCHEDULER_KEY, now()->toIso8601String());
        QueueHeartbeat::dispatch('default');
        QueueHeartbeat::dispatch('heavy');

        $this->getJson('/api/health')->assertOk()->assertJsonPath('status', 'ok');

        // Ten minutes of silence from the heavy worker.
        Cache::put(SystemHealth::queueKey('heavy'), now()->subMinutes(10)->toIso8601String());
        $this->getJson('/api/health')->assertStatus(503)->assertJsonPath('checks.queue_heavy', 'failing')->assertJsonPath('checks.queue_default', 'ok');
    }

    public function test_the_heavy_heartbeat_travels_the_heavy_queue(): void
    {
        config(['queue.heavy.connection' => 'redis-heavy', 'queue.heavy.queue' => 'heavy']);
        $job = new QueueHeartbeat('heavy');

        $this->assertSame('redis-heavy', $job->connection);
        $this->assertSame('heavy', $job->queue);
        $this->assertNull((new QueueHeartbeat('default'))->connection);
    }

    public function test_backups_must_be_recent_and_restore_tested(): void
    {
        $this->backupStatus(['ok' => true, 'finished_at' => now()->subHours(30)->toIso8601String(), 'verified_at' => now()->subDay()->toIso8601String()]);
        $this->getJson('/api/health')->assertStatus(503)->assertJsonPath('checks.backups', 'failing');

        $this->backupStatus(['ok' => true, 'finished_at' => now()->subHours(2)->toIso8601String(), 'verified_at' => null]);
        $this->getJson('/api/health')->assertOk()->assertJsonPath('status', 'warning')->assertJsonPath('checks.backups', 'warning');

        $this->backupStatus(['ok' => true, 'finished_at' => now()->subHours(2)->toIso8601String(), 'verified_at' => now()->subDays(3)->toIso8601String()]);
        $this->getJson('/api/health')->assertOk()->assertJsonPath('checks.backups', 'ok');

        $this->backupStatus(['ok' => false, 'finished_at' => now()->toIso8601String(), 'error' => 'pg_dump failed']);
        $this->getJson('/api/health')->assertStatus(503)->assertJsonPath('checks.backups', 'failing');
    }

    public function test_the_health_check_command_emails_failures_once_per_window(): void
    {
        config(['ops.alert_email' => 'ops@firm.ph', 'ops.expect_workers' => true]);

        $this->assertSame(1, Artisan::call('ops:health-check'));
        $this->assertSame(1, Artisan::call('ops:health-check')); // throttled: no second mail

        $subjects = array_map(fn ($m) => $m->getSubject(), $this->sentMail());
        $this->assertCount(3, $subjects); // scheduler, queue_default, queue_heavy
        $this->assertContains('['.config('app.name').'] Health check failing: scheduler', $subjects);
        $this->assertSame('ops@firm.ph', $this->sentMail()[0]->getTo()[0]->getAddress());
    }

    public function test_a_job_that_fails_for_good_raises_an_alert(): void
    {
        config(['ops.alert_email' => 'ops@firm.ph']);

        try {
            dispatch(new AlwaysFails);
        } catch (RuntimeException) {
            // The sync queue rethrows after marking the job failed.
        }

        $mail = $this->sentMail();
        $this->assertCount(1, $mail);
        $this->assertStringContainsString('Background job failed: AlwaysFails', $mail[0]->getSubject());
        $this->assertStringContainsString('Printer on fire', $mail[0]->getTextBody());
    }

    public function test_without_an_alert_address_nothing_is_sent(): void
    {
        config(['ops.expect_workers' => true]);
        Artisan::call('ops:health-check');

        $this->assertCount(0, $this->sentMail());
    }
}

class AlwaysFails implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        throw new RuntimeException('Printer on fire');
    }
}
