<?php

namespace App\Domain\Tax;

use App\Domain\Deadlines\Services\DeadlineCalculator;
use App\Domain\Matters\Models\Firm;
use App\Domain\Tax\Models\TaxFiling;
use Carbon\CarbonImmutable;

/**
 * The firm's own BIR returns for a tax year, with their usual deadlines
 * (a deadline on a weekend or holiday moves to the next working day).
 *
 * These are the deadlines as commonly applied to manual and eBIRForms
 * filers; eFPS filers have staggered dates by industry group, and the BIR
 * changes schedules from time to time. Every date can be edited, and the
 * firm's accountant should confirm them.
 */
class BirCalendar
{
    public const FORMS = [
        '2550Q' => 'Quarterly VAT return',
        '2551Q' => 'Quarterly percentage tax return',
        '1702Q' => 'Quarterly income tax return (partnership or corporation)',
        '1701Q' => 'Quarterly income tax return (individual)',
        '1702' => 'Annual income tax return (partnership or corporation), with the SAWT',
        '1701' => 'Annual income tax return (individual), with the SAWT',
        '0619E' => 'Monthly remittance of expanded withholding tax',
        '1601EQ' => 'Quarterly remittance return of expanded withholding tax, with the QAP',
        '1604E' => 'Annual information return of expanded withholding tax',
        '1601C' => 'Monthly remittance return of withholding tax on compensation',
        '1604C' => 'Annual information return of withholding tax on compensation',
    ];

    public function __construct(private readonly DeadlineCalculator $calculator) {}

    /** @return list<array{form: string, period: string, due_on: string}> */
    public function forYear(Firm $firm, int $year): array
    {
        $items = [];
        $add = function (string $form, string $period, CarbonImmutable $due) use (&$items) {
            $items[] = ['form' => $form, 'period' => $period, 'due_on' => $this->workingDay($due)->toDateString()];
        };
        $juridical = $firm->taxpayer_type !== 'individual';

        foreach ([1, 2, 3, 4] as $q) {
            $end = CarbonImmutable::create($year, $q * 3, 1)->endOfMonth()->startOfDay();
            $period = "{$year}-Q{$q}";

            // Business tax: 25 days after the quarter.
            $add($firm->vat_registered ? '2550Q' : '2551Q', $period, $end->addDays(25));

            // Quarterly income tax, first three quarters (the annual return covers the fourth).
            if ($q < 4) {
                $add($juridical ? '1702Q' : '1701Q', $period, $juridical ? $end->addDays(60) : CarbonImmutable::create($year, [1 => 5, 2 => 8, 3 => 11][$q], 15));
            }

            // Expanded withholding the firm deducted from its own suppliers and lessors.
            $add('1601EQ', $period, $end->addMonthNoOverflow()->endOfMonth()->startOfDay());
        }

        foreach (range(1, 12) as $month) {
            $next = CarbonImmutable::create($year, $month, 1)->addMonthNoOverflow();
            if ($month % 3 !== 0) {
                $add('0619E', sprintf('%d-%02d', $year, $month), $next->day(10));
            }
            if ($firm->has_employees) {
                $add('1601C', sprintf('%d-%02d', $year, $month), $month === 12 ? $next->day(15) : $next->day(10));
            }
        }

        // Annual returns for the year, due the following year.
        $add($juridical ? '1702' : '1701', (string) $year, CarbonImmutable::create($year + 1, 4, 15));
        $add('1604E', (string) $year, CarbonImmutable::create($year + 1, 3, 1));
        if ($firm->has_employees) {
            $add('1604C', (string) $year, CarbonImmutable::create($year + 1, 1, 31));
        }

        usort($items, fn ($a, $b) => [$a['due_on'], $a['form']] <=> [$b['due_on'], $b['form']]);

        return $items;
    }

    /** Create the year's filings that do not exist yet; existing ones keep their status and edits. */
    public function ensureYear(Firm $firm, int $year): void
    {
        $existing = TaxFiling::where('firm_id', $firm->id)->get(['form', 'period'])
            ->map(fn (TaxFiling $f) => "{$f->form}|{$f->period}")->flip();

        foreach ($this->forYear($firm, $year) as $item) {
            if (! isset($existing["{$item['form']}|{$item['period']}"])) {
                TaxFiling::create(['firm_id' => $firm->id, ...$item]);
            }
        }
    }

    private function workingDay(CarbonImmutable $date): CarbonImmutable
    {
        while (! $this->calculator->isWorkingDay($date)) {
            $date = $date->addDay();
        }

        return $date;
    }
}
