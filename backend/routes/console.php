<?php

use App\Domain\Billing\Collections\Collections;
use App\Domain\Billing\Services\Disbursements;
use App\Domain\Billing\Statements\ClientStatements;
use App\Domain\Billing\TimeReminders\TimeReminders;
use App\Domain\Business\Pipeline;
use App\Domain\Compliance\Services\CounselCredentials;
use App\Domain\Corporate\CorporateSecretarial;
use App\Domain\Deadlines\ClientHearingNotices;
use App\Domain\Deadlines\Services\ReminderDispatcher;
use App\Domain\Documents\Jobs\ExtractMatterFileText;
use App\Domain\Documents\Models\MatterFile;
use App\Domain\Documents\Requests\DocumentRequests;
use App\Domain\Documents\Search\FileTextExtractor;
use App\Domain\EInvoicing\EInvoicing;
use App\Domain\Matters\Models\Firm;
use App\Domain\Prescription\Prescriptions;
use App\Domain\Tax\TaxFilingReminders;
use App\Domain\Trust\Models\TrustAccount;
use App\Domain\Trust\Services\TrustLedgerService;
use App\Domain\Trust\Services\TrustReconciliations;
use App\Jobs\QueueHeartbeat;
use App\Support\Ops\OpsAlert;
use App\Support\Ops\SystemHealth;
use App\Support\Tenancy\TenantContext;
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

// Retainer invoices, payment reminders and trust top-up requests, each at most once.
Artisan::command('billing:collections', function (Collections $collections) {
    $result = $collections->run();
    $this->info("Billed {$result['retainers']} retainer(s); sent {$result['reminders']} payment reminder(s) and {$result['replenishments']} trust top-up request(s).");
})->purpose('Bill monthly retainers, remind clients of unpaid invoices, ask for trust top-ups');
Schedule::command('billing:collections')->dailyAt('09:00')->withoutOverlapping()->onOneServer();

Artisan::command('document-requests:remind', function (DocumentRequests $requests) {
    $this->info("Sent {$requests->sendReminders()} document request reminder(s).");
})->purpose('Remind clients of documents still missing, before and after the due date');
Schedule::command('document-requests:remind')->dailyAt('09:05')->withoutOverlapping()->onOneServer();

Artisan::command('tax:remind', function (TaxFilingReminders $reminders) {
    $this->info("Sent {$reminders->run()} BIR filing reminder(s).");
})->purpose('Remind finance partners of BIR returns coming due or overdue');
Schedule::command('tax:remind')->dailyAt('08:00')->withoutOverlapping()->onOneServer();

Artisan::command('corporate:remind', function (CorporateSecretarial $secretarial) {
    $this->info("Sent {$secretarial->sendReminders()} corporate obligation reminder(s).");
})->purpose('Remind lawyers of client companies\' SEC and BIR obligations coming due or overdue');
Schedule::command('corporate:remind')->dailyAt('08:10')->withoutOverlapping()->onOneServer();

Artisan::command('disbursements:remind', function (Disbursements $disbursements) {
    $this->info("Sent {$disbursements->sendReminders()} cash advance liquidation reminder(s).");
})->purpose('Remind lawyers to liquidate cash advances, and partners once overdue');
Schedule::command('disbursements:remind')->dailyAt('08:20')->withoutOverlapping()->onOneServer();

Artisan::command('prospects:remind', function (Pipeline $pipeline) {
    $this->info("Sent {$pipeline->sendFollowUps()} prospect follow-up reminder(s).");
})->purpose('Remind lawyers of follow-ups due with prospective clients');
Schedule::command('prospects:remind')->dailyAt('08:30')->withoutOverlapping()->onOneServer();

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

Artisan::command('hearings:remind-clients', function (ClientHearingNotices $notices) {
    $this->info("Sent {$notices->sendReminders()} client hearing reminder(s).");
})->purpose('Remind clients of their hearings a week before and the day before (firms that turned it on)');
Schedule::command('hearings:remind-clients')->dailyAt('09:00')->withoutOverlapping()->onOneServer();

Artisan::command('prescriptions:remind', function (Prescriptions $prescriptions, TenantContext $tenant) {
    $total = 0;
    Firm::query()->each(function (Firm $firm) use ($prescriptions, $tenant, &$total) {
        $total += $tenant->runAs($firm->id, fn () => $prescriptions->sendReminders());
    });
    $this->info("Sent {$total} prescription reminder(s).");
})->purpose('Remind lawyers of causes of action about to prescribe: 6 months, 3 months, 30, 14, 7, 3 and 1 days before, on the day and once past');
Schedule::command('prescriptions:remind')->dailyAt('07:45')->withoutOverlapping()->onOneServer();

Artisan::command('einvoices:flag-overdue', function (EInvoicing $eInvoicing, TenantContext $tenant) {
    $total = 0;
    Firm::query()->where('einvoicing_enabled', true)->each(function (Firm $firm) use ($eInvoicing, $tenant, &$total) {
        $total += $tenant->runAs($firm->id, fn () => $eInvoicing->flagOverdue($firm));
    });
    $this->info("Flagged {$total} e-invoice(s) due to reach the BIR and not yet sent.");
})->purpose('Tell finance staff about e-invoices due to reach the BIR that have not been sent');
Schedule::command('einvoices:flag-overdue')->dailyAt('08:30')->withoutOverlapping()->onOneServer();

Artisan::command('files:extract-text {--failed : Also retry files whose extraction failed}', function (FileTextExtractor $extractor) {
    // After new readers are added (e.g. old .doc/.xls/.ppt and Outlook .msg), files
    // uploaded before are still marked "unsupported": queue the ones now readable.
    $statuses = $this->option('failed') ? ['unsupported', 'failed'] : ['unsupported'];
    $queued = 0;
    MatterFile::withoutGlobalScopes()->whereIn('text_status', $statuses)->select(['id', 'original_name'])->chunkById(500, function ($files) use ($extractor, &$queued) {
        foreach ($files as $file) {
            if ($extractor->supports(pathinfo($file->original_name, PATHINFO_EXTENSION))) {
                $file->forceFill(['text_status' => 'pending'])->saveQuietly();
                ExtractMatterFileText::dispatch($file->id);
                $queued++;
            }
        }
    });
    $this->info("Queued {$queued} file(s) for text extraction.");
})->purpose('Re-read files that could not be read before, now that more formats are supported');

Artisan::command('statements:send', function (ClientStatements $statements) {
    $this->info("Sent {$statements->sendDue()} statement(s) of account.");
})->purpose('Email each client their monthly statement of account on the firm\'s statement day (firms that turned it on)');
Schedule::command('statements:send')->dailyAt('08:30')->withoutOverlapping()->onOneServer();

Artisan::command('time:remind', function (TimeReminders $reminders) {
    $this->info("Sent {$reminders->remindMissing()} missing-time reminder(s).");
})->purpose('Remind people who logged less than their daily target on the previous working day (firms that turned it on)');
Schedule::command('time:remind')->weekdays()->at('08:00')->withoutOverlapping()->onOneServer();

Artisan::command('time:weekly-summary', function (TimeReminders $reminders) {
    $this->info("Sent {$reminders->sendWeeklySummary()} weekly time summary(ies).");
})->purpose('Last week\'s hours against target for everyone, to the managing partners (firms that turned it on)');
Schedule::command('time:weekly-summary')->mondays()->at('08:15')->withoutOverlapping()->onOneServer();

Artisan::command('credentials:remind', function (CounselCredentials $credentials) {
    $this->info("Sent {$credentials->sendReminders()} PTR/IBP renewal reminder(s).");
})->purpose('In January, remind lawyers whose PTR or IBP details are not for the new year');
Schedule::command('credentials:remind')->dailyAt('08:45')->withoutOverlapping()->onOneServer();

Artisan::command('trust:reconciliation-reminder', function (TrustReconciliations $reconciliations) {
    $this->info("Sent {$reconciliations->remind()} trust reconciliation reminder(s).");
})->purpose('On the 10th, remind partners when last month\'s trust funds are not yet reconciled with the bank and signed off');
Schedule::command('trust:reconciliation-reminder')->dailyAt('09:15')->withoutOverlapping()->onOneServer();
