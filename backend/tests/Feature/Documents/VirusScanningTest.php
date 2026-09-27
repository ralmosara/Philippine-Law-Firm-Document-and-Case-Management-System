<?php

namespace Tests\Feature\Documents;

use App\Domain\Documents\Scanning\ClamAvScanner;
use App\Domain\Documents\Scanning\ScannerUnavailable;
use App\Domain\Documents\Scanning\ScanResult;
use App\Domain\Documents\Scanning\VirusScanner;
use App\Domain\Matters\Models\Client;
use App\Domain\Matters\Models\Firm;
use App\Domain\Matters\Models\Matter;
use App\Enums\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class VirusScanningTest extends TestCase
{
    use RefreshDatabase;

    private Matter $matter;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $firm = Firm::factory()->create();
        $this->matter = Matter::factory()->for(Client::factory()->for($firm))->create();
        $this->signIn(Role::Associate, $firm);
    }

    public function test_clean_files_are_stored_and_marked_scanned(): void
    {
        $this->useScanner(fn () => ScanResult::clean());

        $this->upload('memo.pdf')->assertCreated()->assertJsonPath('scan_status', 'clean');
        $this->assertNotNull(Storage::disk('local')->allFiles('firms')[0] ?? null);
    }

    public function test_infected_files_are_refused_logged_and_never_stored(): void
    {
        $this->useScanner(fn () => ScanResult::infected('Eicar-Test-Signature'));

        $this->upload('invoice.pdf')
            ->assertStatus(422)
            ->assertJsonPath('errors.file.0', 'This file contains malware (Eicar-Test-Signature) and was not saved.');

        $this->assertDatabaseCount('matter_files', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertDatabaseHas('audit_logs', ['action' => 'file_rejected_malware', 'subject_id' => $this->matter->id]);
    }

    public function test_uploads_stop_when_the_scanner_is_down(): void
    {
        $this->useScanner(fn () => throw new ScannerUnavailable('down'));

        $this->upload('memo.pdf')->assertStatus(503);
        $this->assertDatabaseCount('matter_files', 0);
    }

    public function test_fail_open_stores_the_file_as_not_scanned(): void
    {
        config(['services.clamav.fail_open' => true]);
        $this->useScanner(fn () => throw new ScannerUnavailable('down'));

        $this->upload('memo.pdf')->assertCreated()->assertJsonPath('scan_status', 'not_scanned');
    }

    public function test_without_clamav_files_are_marked_not_scanned(): void
    {
        $this->upload('memo.pdf')->assertCreated()->assertJsonPath('scan_status', 'not_scanned');
    }

    public function test_the_clamav_client_speaks_the_instream_protocol(): void
    {
        foreach ([
            "stream: OK\0" => ScanResult::CLEAN,
            "stream: Eicar-Test-Signature FOUND\0" => ScanResult::INFECTED,
        ] as $reply => $expected) {
            [$client, $server] = stream_socket_pair(STREAM_PF_INET, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            fwrite($server, $reply); // clamd's answer, waiting in the buffer

            $path = tempnam(sys_get_temp_dir(), 'scan');
            file_put_contents($path, str_repeat('A', 10_000)); // two chunks

            $result = (new ClamAvScanner(connect: fn () => $client))->scan($path);

            $sent = stream_get_contents($server);
            fclose($server);
            unlink($path);

            $this->assertSame($expected, $result->status);
            $this->assertSame("zINSTREAM\0".pack('N', 8192).str_repeat('A', 8192).pack('N', 1808).str_repeat('A', 1808).pack('N', 0), $sent);
        }
    }

    public function test_a_clamav_error_reply_is_not_a_verdict(): void
    {
        [$client, $server] = stream_socket_pair(STREAM_PF_INET, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        fwrite($server, "INSTREAM size limit exceeded. ERROR\0");
        $path = tempnam(sys_get_temp_dir(), 'scan');
        file_put_contents($path, 'x');

        $this->expectException(ScannerUnavailable::class);

        try {
            (new ClamAvScanner(connect: fn () => $client))->scan($path);
        } finally {
            unlink($path);
        }
    }

    private function useScanner(\Closure $verdict): void
    {
        $this->app->instance(VirusScanner::class, new class($verdict) implements VirusScanner
        {
            public function __construct(private readonly \Closure $verdict) {}

            public function scan(string $path): ScanResult
            {
                return ($this->verdict)($path);
            }
        });
    }

    private function upload(string $name)
    {
        return $this->postJson("/api/v1/matters/{$this->matter->id}/files", [
            'file' => UploadedFile::fake()->createWithContent($name, '%PDF-1.4 content'),
        ]);
    }
}
