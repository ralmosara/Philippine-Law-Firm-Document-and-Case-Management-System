<?php

namespace Database\Seeders;

use App\Domain\Compliance\Models\McleCompliancePeriod;
use App\Domain\Deadlines\Models\DeadlineRule;
use App\Domain\Deadlines\Models\HolidayCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Nationwide reference data: holidays, Rules of Court periods and MCLE
 * compliance periods. Idempotent, so it is safe to run on every deploy.
 *
 * IMPORTANT: holidays whose dates are fixed by annual proclamation (Eid'l
 * Fitr, Eid'l Adha, ad hoc special days, court closures for typhoons) are
 * NOT seeded. A managing partner must add them from Settings as they are
 * proclaimed, or deadline computations will be wrong.
 */
class ReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([2025, 2026, 2027] as $year) {
            foreach ($this->holidaysFor($year) as [$date, $name, $type]) {
                HolidayCalendar::updateOrCreate(['date' => $date], ['name' => $name, 'type' => $type]);
            }
        }

        foreach ($this->rulesOfCourt() as $rule) {
            DeadlineRule::updateOrCreate(['firm_id' => null, 'name' => $rule['name']], $rule);
        }

        foreach ([['2022-04-15', '2025-04-14'], ['2025-04-15', '2028-04-14']] as [$start, $end]) {
            McleCompliancePeriod::updateOrCreate(
                ['start_date' => $start],
                ['name' => 'Compliance Period '.substr($start, 0, 4).'–'.substr($end, 0, 4), 'end_date' => $end, 'required_units' => 36],
            );
        }
    }

    /**
     * Regular holidays and special non-working days with fixed or
     * computable dates (RA 9492 and recurring proclamations).
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private function holidaysFor(int $year): array
    {
        $easter = $this->easterSunday($year);
        $heroesDay = CarbonImmutable::create($year, 8, 31)->modify('last monday of august');

        return [
            ["{$year}-01-01", "New Year's Day", 'regular'],
            [$easter->subDays(3)->toDateString(), 'Maundy Thursday', 'regular'],
            [$easter->subDays(2)->toDateString(), 'Good Friday', 'regular'],
            [$easter->subDay()->toDateString(), 'Black Saturday', 'special_non_working'],
            ["{$year}-04-09", 'Araw ng Kagitingan', 'regular'],
            ["{$year}-05-01", 'Labor Day', 'regular'],
            ["{$year}-06-12", 'Independence Day', 'regular'],
            ["{$year}-08-21", 'Ninoy Aquino Day', 'special_non_working'],
            [$heroesDay->toDateString(), 'National Heroes Day', 'regular'],
            ["{$year}-11-01", "All Saints' Day", 'special_non_working'],
            ["{$year}-11-30", 'Bonifacio Day', 'regular'],
            ["{$year}-12-08", 'Feast of the Immaculate Conception', 'special_non_working'],
            ["{$year}-12-24", 'Christmas Eve', 'special_non_working'],
            ["{$year}-12-25", 'Christmas Day', 'regular'],
            ["{$year}-12-30", 'Rizal Day', 'regular'],
            ["{$year}-12-31", 'Last Day of the Year', 'special_non_working'],
        ];
    }

    /** Anonymous Gregorian algorithm (Meeus/Jones/Butcher). */
    private function easterSunday(int $year): CarbonImmutable
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return CarbonImmutable::create($year, $month, $day);
    }

    /**
     * Common reglementary periods. Firms should have counsel verify these
     * against current rules and add their own under Settings.
     */
    private function rulesOfCourt(): array
    {
        $rule = fn (string $name, string $trigger, int $days, string $basis, string $type = 'calendar', ?string $notes = null) => [
            'name' => $name,
            'trigger_event' => $trigger,
            'period_days' => $days,
            'period_type' => $type,
            'legal_basis' => $basis,
            'notes' => $notes,
            'is_active' => true,
        ];

        return [
            $rule('Answer to Complaint', 'Service of summons', 30, 'Rules of Court, Rule 11, Sec. 1 (2019 Amendments)'),
            $rule('Reply', 'Service of the answer', 15, 'Rules of Court, Rule 11, Sec. 4 (2019 Amendments)', notes: 'Only where the answer alleges an actionable document.'),
            $rule('Motion for Reconsideration / New Trial', 'Notice of judgment or final order', 15, 'Rules of Court, Rule 37, Sec. 1'),
            $rule('Notice of Appeal (Ordinary Appeal)', 'Notice of judgment or final order', 15, 'Rules of Court, Rule 41, Sec. 3'),
            $rule('Petition for Review (RTC to CA)', 'Notice of decision or denial of MR', 15, 'Rules of Court, Rule 42, Sec. 1'),
            $rule("Appellant's Brief (Court of Appeals)", 'Notice that records have been received', 45, 'Rules of Court, Rule 44, Sec. 7'),
            $rule("Appellee's Brief (Court of Appeals)", "Receipt of appellant's brief", 45, 'Rules of Court, Rule 44, Sec. 8'),
            $rule('Petition for Review on Certiorari (SC)', 'Notice of judgment or denial of MR', 15, 'Rules of Court, Rule 45, Sec. 2'),
            $rule('Petition for Certiorari / Prohibition / Mandamus', 'Notice of judgment or denial of MR', 60, 'Rules of Court, Rule 65, Sec. 4'),
            $rule('Appeal in Criminal Case', 'Promulgation of judgment or notice of final order', 15, 'Rules of Court, Rule 122, Sec. 6'),
            $rule('Answer to Petition (Nullity / Annulment)', 'Service of summons', 15, 'A.M. No. 02-11-10-SC, Sec. 8'),
            $rule('Appeal to NLRC from Labor Arbiter', 'Receipt of Labor Arbiter decision', 10, 'Labor Code, Art. 229; 2011 NLRC Rules, Rule VI', notes: 'Ten calendar days; appeal bond required for monetary awards.'),
        ];
    }
}
