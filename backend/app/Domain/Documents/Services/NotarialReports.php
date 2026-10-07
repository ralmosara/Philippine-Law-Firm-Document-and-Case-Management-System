<?php

namespace App\Domain\Documents\Services;

use App\Domain\Documents\Models\NotarialEntry;
use App\Domain\Documents\Models\NotarialReport;
use App\Domain\Documents\Notifications\NotarialReportDue;
use App\Domain\Matters\Models\Firm;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The monthly report a notary public sends the clerk of court: a certified
 * copy of the month's register entries (or a statement that there were
 * none), due within the first ten days of the next month. The exact form
 * and deadline the firm should confirm with the Office of the Clerk of
 * Court where its notaries are commissioned.
 */
class NotarialReports
{
    public const DUE_DAY = 10;

    /** Days of the month on which a notary with last month's report still unsubmitted is reminded. */
    public const REMINDER_DAYS = [1, 5, 9];

    /** Lawyers who notarise: a commission on file, or entries in the register. */
    public function notaries(): Collection
    {
        $withEntries = NotarialEntry::query()->distinct()->pluck('notary_id');

        return User::query()->where('is_active', true)
            ->where(fn ($q) => $q->whereNotNull('notarial_commission_number')->orWhereIn('id', $withEntries))
            ->orderBy('name')->get();
    }

    public function entries(User $notary, CarbonImmutable $month): Collection
    {
        return NotarialEntry::query()->where('notary_id', $notary->id)
            ->whereBetween('notarized_at', [$month->startOfMonth()->startOfDay(), $month->endOfMonth()->endOfDay()])
            ->orderBy('book_number')->orderBy('page_number')->orderBy('doc_number')->get();
    }

    /** The record for a month, created on first look (without submitting it). */
    public function report(User $notary, CarbonImmutable $month): NotarialReport
    {
        $period = $month->startOfMonth()->toDateString();
        $count = $this->entries($notary, $month)->count();
        $report = NotarialReport::query()->where('notary_id', $notary->id)->whereDate('period', $period)->first()
            ?? NotarialReport::create(['firm_id' => $notary->firm_id, 'notary_id' => $notary->id, 'period' => $period, 'entries' => $count]);
        if ($report->submitted_at === null && $report->entries !== $count) {
            $report->forceFill(['entries' => $count])->save();
        }

        return $report;
    }

    public function markSubmitted(NotarialReport $report, ?string $notes, User $by): NotarialReport
    {
        $report->forceFill(['submitted_at' => now(), 'submitted_by' => $by->id, 'notes' => $notes])->save();
        AuditLog::record('notarial_report_submitted', $report->firm_id, $by, $report->notary, ['period' => $report->period->format('Y-m'), 'entries' => $report->entries]);

        return $report;
    }

    /** On the reminder days: each notary whose report for last month has not been marked submitted. */
    public function remind(?CarbonImmutable $today = null): int
    {
        $today ??= CarbonImmutable::today();
        if (! in_array($today->day, self::REMINDER_DAYS, true)) {
            return 0;
        }
        $month = $today->subMonthNoOverflow()->startOfMonth();
        $sent = 0;

        Firm::query()->pluck('id')->each(function (int $firmId) use ($today, $month, &$sent) {
            app(TenantContext::class)->runAs($firmId, function () use ($today, $month, &$sent) {
                foreach ($this->notaries() as $notary) {
                    $report = $this->report($notary, $month);
                    if ($report->submitted_at !== null || $report->last_reminded_on?->toDateString() === $today->toDateString()) {
                        continue;
                    }
                    $report->forceFill(['last_reminded_on' => $today->toDateString()])->save();
                    $notary->notify(new NotarialReportDue($report, $month->day(self::DUE_DAY)));
                    $sent++;
                }
            });
        });

        return $sent;
    }
}
