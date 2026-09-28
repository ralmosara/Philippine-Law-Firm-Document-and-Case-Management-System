<?php

namespace App\Domain\Corporate;

use App\Domain\Corporate\Models\CorporateObligation;
use App\Domain\Corporate\Models\CorporateProfile;
use App\Domain\Corporate\Notifications\CorporateObligationDue;
use App\Domain\Deadlines\Services\DeadlineCalculator;
use App\Domain\Documents\Models\DocumentTemplate;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The yearly housekeeping of client companies, generated from their
 * profile, with reminders to the responsible lawyer:
 *  - the annual stockholders' meeting, on the date the by-laws fix;
 *  - the GIS, within 30 days after that meeting (SEC);
 *  - the audited financial statements, within 120 days after the fiscal
 *    year ends (the SEC also publishes a coded filing schedule each year;
 *    adjust the date to it);
 *  - the BIR annual income tax return, by the 15th day of the fourth month
 *    after the fiscal year ends.
 * Dates falling on a weekend or holiday move to the next working day and
 * can be edited.
 */
class CorporateSecretarial
{
    public function __construct(
        private readonly DeadlineCalculator $calculator,
        private readonly TenantContext $tenant,
    ) {}

    /** @return list<array{kind: string, title: string, year: int, due_on: string}> */
    public function obligationsFor(CorporateProfile $profile, int $year): array
    {
        [$fyeMonth, $fyeDay] = array_map('intval', explode('-', $profile->fiscal_year_end ?: '12-31'));
        $fye = CarbonImmutable::create($year, $fyeMonth, 1)->day(min($fyeDay, CarbonImmutable::create($year, $fyeMonth, 1)->daysInMonth));
        $items = [];

        if ($profile->annual_meeting_date) {
            [$m, $d] = array_map('intval', explode('-', $profile->annual_meeting_date));
            $meeting = CarbonImmutable::create($year, $m, 1)->day(min($d, CarbonImmutable::create($year, $m, 1)->daysInMonth));
            $items[] = ['kind' => 'annual_meeting', 'title' => "Annual stockholders' meeting {$year}", 'year' => $year, 'due_on' => $meeting->toDateString()];
            $items[] = ['kind' => 'gis', 'title' => "General Information Sheet {$year}", 'year' => $year, 'due_on' => $this->workingDay($meeting->addDays(30))->toDateString()];
        }

        $items[] = ['kind' => 'afs', 'title' => "Audited financial statements, FY ending {$fye->format('M j, Y')}", 'year' => $year, 'due_on' => $this->workingDay($fye->addDays(120))->toDateString()];
        $items[] = ['kind' => 'annual_itr', 'title' => "BIR annual income tax return, FY ending {$fye->format('M j, Y')}", 'year' => $year, 'due_on' => $this->workingDay($fye->addMonthsNoOverflow(4)->startOfMonth()->day(15))->toDateString()];

        return $items;
    }

    /**
     * Add the year's obligations that do not exist yet; done or edited ones
     * stay as they are. Obligations that fell due before the company was
     * tracked here are not back-filled: they were handled elsewhere.
     */
    public function ensureYear(CorporateProfile $profile, int $year): void
    {
        $since = ($profile->created_at ?? now())->toDateString();

        foreach ($this->obligationsFor($profile, $year) as $item) {
            if ($item['due_on'] < $since) {
                continue;
            }
            CorporateObligation::firstOrCreate(
                ['client_id' => $profile->client_id, 'kind' => $item['kind'], 'year' => $item['year']],
                ['firm_id' => $profile->firm_id, 'title' => $item['title'], 'due_on' => $item['due_on']],
            );
        }
    }

    /**
     * Reminders to the responsible lawyer 30 days, 7 days and a day before,
     * and once when overdue. Keeps every profile's current and next year filled.
     */
    public function sendReminders(?CarbonImmutable $today = null): int
    {
        $today ??= CarbonImmutable::today();

        CorporateProfile::withoutGlobalScopes()->each(function (CorporateProfile $profile) use ($today) {
            $this->tenant->runAs($profile->firm_id, function () use ($profile, $today) {
                $this->ensureYear($profile, $today->year);
                $this->ensureYear($profile, $today->year + 1);
            });
        });

        $sent = 0;
        CorporateObligation::withoutGlobalScopes()
            ->where('status', CorporateObligation::PENDING)
            ->whereDate('due_on', '<=', $today->addDays(30)->toDateString())
            ->orderBy('due_on')
            ->each(function (CorporateObligation $obligation) use ($today, &$sent) {
                $days = (int) $today->diffInDays(CarbonImmutable::instance($obligation->due_on), false);
                $stage = match (true) {
                    $days < 0 => 'overdue',
                    $days <= 1 => 'day',
                    $days <= 7 => 'week',
                    default => 'month',
                };
                $order = ['month' => 1, 'week' => 2, 'day' => 3, 'overdue' => 4];
                if ($obligation->last_reminder && $order[$obligation->last_reminder] >= $order[$stage]) {
                    return;
                }

                $claimed = DB::table('corporate_obligations')->where('id', $obligation->id)
                    ->where(fn ($q) => $q->whereNull('last_reminder')->orWhere('last_reminder', '!=', $stage))
                    ->update(['last_reminder' => $stage]);
                if ($claimed !== 1) {
                    return;
                }

                $this->tenant->runAs($obligation->firm_id, function () use ($obligation, $stage, &$sent) {
                    $profile = CorporateProfile::where('client_id', $obligation->client_id)->first();
                    $lawyer = User::where('is_active', true)->find($profile?->responsible_lawyer_id);
                    if ($lawyer) {
                        $lawyer->notify(new CorporateObligationDue($obligation->load('client:id,name'), $stage));
                        $sent++;
                    }
                });
            });

        return $sent;
    }

    /**
     * The firm's standard corporate templates, filled from the matter and the
     * client's corporate profile. Installed once; existing ones are left alone.
     *
     * @return int templates added
     */
    public function installTemplates(int $firmId): int
    {
        $added = 0;
        foreach (CorporateTemplates::all() as $name => $body) {
            if (! DocumentTemplate::where('name', $name)->exists()) {
                DocumentTemplate::create(['firm_id' => $firmId, 'name' => $name, 'category' => 'corporate', 'body' => $body]);
                $added++;
            }
        }

        return $added;
    }

    private function workingDay(CarbonImmutable $date): CarbonImmutable
    {
        while (! $this->calculator->isWorkingDay($date)) {
            $date = $date->addDay();
        }

        return $date;
    }
}
