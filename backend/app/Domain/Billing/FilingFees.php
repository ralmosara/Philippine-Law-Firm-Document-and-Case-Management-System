<?php

namespace App\Domain\Billing;

use App\Domain\Matters\Models\Firm;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Estimates the docket and other lawful fees for filing a money claim from
 * the amount claimed, using the firm's fee table (Rule 141, as amended).
 *
 * The table is data, not law: courts' fees change by Supreme Court
 * resolution and OCA circular, and they differ by court and by the kind of
 * action. The firm keeps its own table; until a managing partner has
 * reviewed it against the current rules and confirmed it, no estimate is
 * given. The starting table below is a placeholder to edit, NOT verified.
 *
 * Table format (centavos):
 *   brackets: [{under_cents, fee_cents}]   first bracket whose limit the amount is below
 *   excess:   {from_cents, base_cents, per_thousand_cents}   above the last bracket
 *   extras:   [{label, fixed_cents?, percent_bps?, minimum_cents?}]   percent is of the filing fee
 */
class FilingFees
{
    /** A starting point to review and correct before use. Not verified against current fees. */
    public const STARTING_SCHEDULE = [
        'court' => 'Regional Trial Court: money claims',
        'brackets' => [
            ['under_cents' => 10_000_000, 'fee_cents' => 100_000],
            ['under_cents' => 15_000_000, 'fee_cents' => 150_000],
            ['under_cents' => 20_000_000, 'fee_cents' => 200_000],
            ['under_cents' => 25_000_000, 'fee_cents' => 250_000],
            ['under_cents' => 30_000_000, 'fee_cents' => 300_000],
            ['under_cents' => 40_000_000, 'fee_cents' => 350_000],
        ],
        'excess' => ['from_cents' => 40_000_000, 'base_cents' => 350_000, 'per_thousand_cents' => 1_000],
        'extras' => [
            ['label' => 'Legal Research Fund (RA 3870)', 'percent_bps' => 100, 'minimum_cents' => 1_000],
            ['label' => 'Mediation fee', 'fixed_cents' => 50_000],
        ],
    ];

    public function schedule(Firm $firm): array
    {
        return $firm->filing_fee_schedule ?? self::STARTING_SCHEDULE;
    }

    public function isConfirmed(Firm $firm): bool
    {
        return $firm->filing_fee_schedule !== null && $firm->filing_fee_schedule_confirmed_at !== null;
    }

    /** Save the table as reviewed by $by today. */
    public function confirm(Firm $firm, array $schedule, User $by): Firm
    {
        $previous = $firm->filing_fee_schedule;
        $firm->forceFill(['filing_fee_schedule' => $schedule, 'filing_fee_schedule_confirmed_at' => now(), 'filing_fee_schedule_confirmed_by' => $by->id])->save();
        AuditLog::record('filing_fee_schedule_confirmed', $firm->id, $by, null, ['before' => $previous, 'after' => $schedule]);

        return $firm;
    }

    /**
     * @return array{lines: list<array{label: string, amount_cents: int}>, total_cents: int}
     */
    public function estimate(Firm $firm, int $claimCents): array
    {
        if (! $this->isConfirmed($firm)) {
            throw ValidationException::withMessages(['schedule' => 'The filing fee table has not been reviewed yet. A managing partner must check it against the current Rule 141 and confirm it (Firm Settings → Firm).']);
        }
        $schedule = $this->schedule($firm);
        $filing = $this->filingFee($schedule, $claimCents);

        $lines = [['label' => 'Filing (docket) fee', 'amount_cents' => $filing]];
        foreach ($schedule['extras'] ?? [] as $extra) {
            $amount = isset($extra['percent_bps'])
                ? max((int) ($extra['minimum_cents'] ?? 0), intdiv($filing * (int) $extra['percent_bps'] + 5_000, 10_000))
                : (int) ($extra['fixed_cents'] ?? 0);
            if ($amount > 0) {
                $lines[] = ['label' => (string) $extra['label'], 'amount_cents' => $amount];
            }
        }

        return ['lines' => $lines, 'total_cents' => array_sum(array_column($lines, 'amount_cents'))];
    }

    private function filingFee(array $schedule, int $claimCents): int
    {
        foreach ($schedule['brackets'] ?? [] as $bracket) {
            if ($claimCents < (int) $bracket['under_cents']) {
                return (int) $bracket['fee_cents'];
            }
        }
        $excess = $schedule['excess'] ?? null;
        if ($excess === null) {
            $last = end($schedule['brackets']);

            return (int) ($last['fee_cents'] ?? 0);
        }
        // Each ₱1,000 or fraction above the threshold.
        $thousands = (int) ceil(max(0, $claimCents - (int) $excess['from_cents']) / 100_000);

        return (int) $excess['base_cents'] + $thousands * (int) $excess['per_thousand_cents'];
    }
}
