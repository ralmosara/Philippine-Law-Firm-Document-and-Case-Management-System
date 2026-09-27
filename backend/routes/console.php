<?php

use App\Domain\Deadlines\Services\ReminderDispatcher;
use App\Domain\Trust\Models\TrustAccount;
use App\Domain\Trust\Services\TrustLedgerService;
use App\Jobs\QueueHeartbeat;
use App\Support\Ops\OpsAlert;
use App\Support\Ops\SystemHealth;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('deadlines:dispatch-reminders', function (ReminderDispatcher $dispatcher) {
    $result = $dispatcher->run();
    $this->info("Queued {$result['reminders']} reminder(s); marked {$result['missed']} deadline(s) missed.");
})->purpose('Queue deadline reminders and flag missed deadlines');

Artisan::command('trust:reconcile', function (TrustLedgerService $ledger) {
    $failures = 0;

    TrustAccount::withoutGlobalScopes()->orderBy('id')->each(function (TrustAccount $account) use ($ledger, &$failures) {
        $problems = $ledger->reconcile($account);

        if ($problems !== []) {
            $failures++;
            $this->error("{$account->account_number} (firm {$account->firm_id}):");
            foreach ($problems as $problem) {
                $this->line("  - {$problem}");
            }
            // Trust discrepancies are a disciplinary matter: always leave a trail.
            Log::critical('Trust account failed reconciliation', ['account_id' => $account->id, 'problems' => $problems]);
        }
    });

    if ($failures === 0) {
        $this->info('All trust accounts reconcile.');
    } else {
        OpsAlert::send('trust:reconcile', "{$failures} trust account(s) failed reconciliation", 'Run "php artisan trust:reconcile" for details. Trust discrepancies must be investigated promptly.');
    }

    return $failures === 0 ? 0 : 1;
})->purpose('Verify every trust ledger against its running balances');

// Hourly, so a deadline created late in the day still gets its reminder.
Schedule::command('deadlines:dispatch-reminders')->hourly()->withoutOverlapping()->onOneServer();
Schedule::command('trust:reconcile')->dailyAt('01:00')->onOneServer();

// Heartbeats: the scheduler proves it runs, and each queue proves a worker
// is taking jobs. SystemHealth reports a stale one.
Schedule::call(fn () => Cache::put(SystemHealth::SCHEDULER_KEY, now()->toIso8601String(), now()->addDay()))
    ->name('ops:scheduler-heartbeat')->everyMinute()->onOneServer();
Schedule::job(new QueueHeartbeat('default'))->name('ops:queue-heartbeat')->everyMinute()->onOneServer();
Schedule::job(new QueueHeartbeat('heavy'))->name('ops:heavy-queue-heartbeat')->everyMinute()->onOneServer();

Artisan::command('ops:health-check', function (SystemHealth $health) {
    $checks = $health->run();

    foreach ($checks as $name => $check) {
        $line = str_pad($name, 16).str_pad($check['status'], 9).$check['message'];
        $check['status'] === SystemHealth::OK ? $this->line($line) : $this->error($line);

        if ($check['status'] === SystemHealth::FAILING) {
            OpsAlert::send("health:{$name}", "Health check failing: {$name}", $check['message']);
        }
    }

    return SystemHealth::overall($checks) === SystemHealth::FAILING ? 1 : 0;
})->purpose('Check the database, workers, scheduler, scanner, backups and disk; alert on failures');

// Run by the scheduler, so it cannot report the scheduler itself stopping:
// point an external uptime monitor at /api/health for that.
Schedule::command('ops:health-check')->everyFiveMinutes()->withoutOverlapping()->onOneServer();
