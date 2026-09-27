<?php

namespace App\Domain\Tax;

use App\Domain\Matters\Models\Firm;
use App\Domain\Tax\Models\TaxFiling;
use App\Domain\Tax\Notifications\TaxFilingDue;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Reminds the partners who handle finances of BIR returns not yet marked
 * filed: a week before, the day before, and once if the date passes.
 */
class TaxFilingReminders
{
    public function __construct(private readonly TenantContext $tenant, private readonly BirCalendar $calendar) {}

    public function run(?CarbonImmutable $today = null): int
    {
        $today ??= CarbonImmutable::today();
        $sent = 0;

        // Keep each firm's calendar filled for this year and next (January returns).
        Firm::query()->each(function (Firm $firm) use ($today) {
            $this->tenant->runAs($firm->id, function () use ($firm, $today) {
                $this->calendar->ensureYear($firm, $today->year);
                if ($today->month === 12) {
                    $this->calendar->ensureYear($firm, $today->year + 1);
                }
            });
        });

        TaxFiling::withoutGlobalScopes()
            ->where('status', TaxFiling::PENDING)
            ->whereDate('due_on', '<=', $today->addDays(7)->toDateString())
            ->orderBy('due_on')
            ->each(function (TaxFiling $filing) use ($today, &$sent) {
                $days = (int) $today->diffInDays(CarbonImmutable::instance($filing->due_on), false);
                $stage = match (true) {
                    $days < 0 => 'overdue',
                    $days <= 1 => 'tomorrow',
                    default => 'week',
                };
                if ($filing->last_reminder === $stage || ($filing->last_reminder === 'overdue') || ($stage === 'week' && $filing->last_reminder === 'tomorrow')) {
                    return;
                }

                // Claim the stage so overlapping runs send it once.
                $claimed = DB::table('tax_filings')->where('id', $filing->id)
                    ->where(fn ($q) => $q->whereNull('last_reminder')->orWhere('last_reminder', '!=', $stage))
                    ->update(['last_reminder' => $stage]);
                if ($claimed !== 1) {
                    return;
                }

                $this->tenant->runAs($filing->firm_id, function () use ($filing, $stage) {
                    $recipients = User::where('firm_id', $filing->firm_id)->where('is_active', true)->get()
                        ->filter(fn (User $u) => $u->role->canManageFinances());
                    Notification::send($recipients, new TaxFilingDue($filing, $stage));
                });
                $sent++;
            });

        return $sent;
    }
}
