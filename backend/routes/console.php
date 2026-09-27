<?php

use App\Domain\Deadlines\Services\ReminderDispatcher;
use App\Domain\Trust\Models\TrustAccount;
use App\Domain\Trust\Services\TrustLedgerService;
use Illuminate\Support\Facades\Artisan;
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
    }

    return $failures === 0 ? 0 : 1;
})->purpose('Verify every trust ledger against its running balances');

// Hourly, so a deadline created late in the day still gets its reminder.
Schedule::command('deadlines:dispatch-reminders')->hourly()->withoutOverlapping()->onOneServer();
Schedule::command('trust:reconcile')->dailyAt('01:00')->onOneServer();
