<?php

namespace App\Support\Ops;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Checks everything the firm depends on silently: a stopped scheduler or
 * queue worker means deadline reminders quietly stop going out.
 *
 * Each check is ok, warning (look at it soon) or failing (broken now).
 */
class SystemHealth
{
    public const OK = 'ok';

    public const WARNING = 'warning';

    public const FAILING = 'failing';

    public const SCHEDULER_KEY = 'ops:heartbeat:scheduler';

    public static function queueKey(string $queue): string
    {
        return "ops:heartbeat:queue:{$queue}";
    }

    /** @return array<string, array{status: string, message: string}> */
    public function run(): array
    {
        $checks = [
            'database' => $this->database(),
            'cache' => $this->cache(),
        ];

        if (config('queue.default') === 'redis' || config('cache.default') === 'redis' || config('session.driver') === 'redis') {
            $checks['redis'] = $this->redis();
        }

        if (config('ops.expect_workers')) {
            $checks['scheduler'] = $this->heartbeat(self::SCHEDULER_KEY, 'The scheduler');
            $checks['queue_default'] = $this->heartbeat(self::queueKey('default'), 'The queue worker (reminders, email)');
            $checks['queue_heavy'] = $this->heartbeat(self::queueKey('heavy'), 'The heavy-queue worker (OCR, assistant)');
        }

        $checks['failed_jobs'] = $this->failedJobs();

        if (config('services.clamav.enabled')) {
            $checks['virus_scanner'] = $this->clamav();
        }

        if (filled(config('ops.backup_status_file'))) {
            $checks['backups'] = $this->backups();
        }

        $checks['disk'] = $this->disk();

        return $checks;
    }

    /** @param  array<string, array{status: string}>  $checks */
    public static function overall(array $checks): string
    {
        $statuses = array_column($checks, 'status');

        return match (true) {
            in_array(self::FAILING, $statuses, true) => self::FAILING,
            in_array(self::WARNING, $statuses, true) => self::WARNING,
            default => self::OK,
        };
    }

    private function database(): array
    {
        try {
            DB::select('select 1');

            return $this->ok('Connected');
        } catch (Throwable $e) {
            return $this->failing('Cannot query the database: '.class_basename($e));
        }
    }

    private function cache(): array
    {
        try {
            // A string: Redis hands numbers back as strings.
            $probe = bin2hex(random_bytes(8));
            Cache::put('ops:health:probe', $probe, 60);

            return Cache::get('ops:health:probe') === $probe ? $this->ok('Read and write work') : $this->failing('Cache wrote but did not read back');
        } catch (Throwable $e) {
            return $this->failing('Cache unavailable: '.class_basename($e));
        }
    }

    private function redis(): array
    {
        try {
            Redis::connection()->ping();

            return $this->ok('Responding');
        } catch (Throwable $e) {
            return $this->failing('Redis unavailable: '.class_basename($e));
        }
    }

    private function heartbeat(string $key, string $what): array
    {
        $last = Cache::get($key);

        if ($last === null) {
            return $this->failing("{$what} has not reported in.");
        }

        $minutes = (int) CarbonImmutable::parse($last)->diffInMinutes(now(), true);

        return $minutes > config('ops.heartbeat_stale_minutes')
            ? $this->failing("{$what} last reported {$minutes} minutes ago.")
            : $this->ok("Last reported {$minutes} min ago");
    }

    private function failedJobs(): array
    {
        try {
            $count = DB::table(config('queue.failed.table', 'failed_jobs'))->where('failed_at', '>=', now()->subDay())->count();
        } catch (Throwable) {
            return $this->warning('Could not read failed jobs');
        }

        return $count === 0
            ? $this->ok('None in the last 24 hours')
            : $this->warning("{$count} failed in the last 24 hours. Check: php artisan queue:failed");
    }

    private function clamav(): array
    {
        $socket = @fsockopen((string) config('services.clamav.host'), (int) config('services.clamav.port'), $errno, $error, 3);

        if ($socket === false) {
            return $this->failing('Unreachable, so uploads are refused. It may still be loading its signatures after a restart.');
        }

        stream_set_timeout($socket, 3);
        fwrite($socket, "zPING\0");
        $reply = trim((string) fread($socket, 64), "\0\n ");
        fclose($socket);

        return $reply === 'PONG' ? $this->ok('Responding') : $this->failing('Unexpected reply');
    }

    private function backups(): array
    {
        $path = (string) config('ops.backup_status_file');
        $status = is_readable($path) ? json_decode((string) file_get_contents($path), true) : null;

        if (! is_array($status) || empty($status['finished_at'])) {
            return $this->failing('No backup has been recorded yet.');
        }

        if (($status['ok'] ?? false) !== true) {
            return $this->failing('The last backup failed: '.($status['error'] ?? 'see the backup container log'));
        }

        // The last successful backup, not merely the last time the status was written.
        $hours = (int) CarbonImmutable::parse($status['backed_up_at'] ?? $status['finished_at'])->diffInHours(now(), true);
        if ($hours > config('ops.backup_max_age_hours')) {
            return $this->failing("The last backup is {$hours} hours old.");
        }

        $verified = $status['verified_at'] ?? null;
        if ($verified === null || CarbonImmutable::parse($verified)->diffInDays(now(), true) > config('ops.backup_verify_max_age_days')) {
            return $this->warning("Backed up {$hours} h ago, but no recent restore test.");
        }

        return $this->ok("Backed up {$hours} h ago; restore tested ".CarbonImmutable::parse($verified)->toDateString());
    }

    private function disk(): array
    {
        $path = storage_path('app');
        $free = @disk_free_space($path);
        $total = @disk_total_space($path);

        if (! $free || ! $total) {
            return $this->warning('Could not read disk space');
        }

        $percent = (int) floor($free / $total * 100);
        $gb = round($free / 1024 ** 3, 1);

        return match (true) {
            $percent < 5 => $this->failing("Only {$percent}% ({$gb} GB) free for uploads."),
            $percent < 10 => $this->warning("{$percent}% ({$gb} GB) free for uploads."),
            default => $this->ok("{$percent}% ({$gb} GB) free"),
        };
    }

    private function ok(string $message): array
    {
        return ['status' => self::OK, 'message' => $message];
    }

    private function warning(string $message): array
    {
        return ['status' => self::WARNING, 'message' => $message];
    }

    private function failing(string $message): array
    {
        return ['status' => self::FAILING, 'message' => $message];
    }
}
