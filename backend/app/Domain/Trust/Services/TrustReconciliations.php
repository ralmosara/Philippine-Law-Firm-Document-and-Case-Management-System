<?php

namespace App\Domain\Trust\Services;

use App\Domain\Matters\Models\Firm;
use App\Domain\Trust\Models\TrustAccount;
use App\Domain\Trust\Models\TrustReconciliation;
use App\Domain\Trust\Notifications\TrustReconciliationDue;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The monthly three-way reconciliation of client funds held in trust:
 *
 *  1. the bank: the statement balance, plus deposits not yet on the
 *     statement, less cheques issued but not yet cleared;
 *  2. the trust ledger: every posting up to the end of the month; and
 *  3. the clients: each account's balance at the end of the month, summed.
 *
 * All three must agree and no client may be below zero. A partner signs it
 * off; once signed it cannot be changed. The ledger is append-only, so the
 * figures for a past month never move.
 */
class TrustReconciliations
{
    /** Day of the month by which last month should be reconciled. */
    public const DUE_DAY = 10;

    /**
     * @return array{ledger_cents: int, client_total_cents: int, exceptions: list<string>, accounts: list<array{account: string, client: ?string, matter: ?string, balance_cents: int}>}
     */
    public function figures(CarbonImmutable $periodEnd): array
    {
        $end = $periodEnd->endOfDay();
        $accounts = TrustAccount::query()->with(['client:id,name', 'matter:id,reference'])->orderBy('account_number')->get();
        $ids = $accounts->modelKeys();

        $sums = DB::table('trust_transactions')->whereIn('trust_account_id', $ids)->where('created_at', '<=', $end)
            ->groupBy('trust_account_id')
            ->selectRaw("trust_account_id, SUM(CASE WHEN type = 'deposit' THEN amount_cents ELSE -amount_cents END) AS total")
            ->pluck('total', 'trust_account_id');
        $lastIds = DB::table('trust_transactions')->whereIn('trust_account_id', $ids)->where('created_at', '<=', $end)
            ->groupBy('trust_account_id')->selectRaw('MAX(id) AS id')->pluck('id');
        $last = DB::table('trust_transactions')->whereIn('id', $lastIds)->pluck('balance_after_cents', 'trust_account_id');

        $exceptions = [];
        $rows = [];
        foreach ($accounts as $account) {
            if (! $sums->has($account->id)) {
                continue;   // opened after the period
            }
            $posted = (int) $sums[$account->id];
            $balance = (int) $last[$account->id];
            if ($balance < 0) {
                $exceptions[] = "{$account->account_number} ({$account->client?->name}) is below zero: ".$this->peso($balance).'.';
            }
            if ($posted !== $balance) {
                $exceptions[] = "{$account->account_number}: its postings add up to ".$this->peso($posted).' but its running balance shows '.$this->peso($balance).'.';
            }
            $rows[] = ['account' => $account->account_number, 'client' => $account->client?->name, 'matter' => $account->matter?->reference, 'balance_cents' => $balance];
        }

        return [
            'ledger_cents' => (int) $sums->sum(),
            'client_total_cents' => (int) array_sum(array_column($rows, 'balance_cents')),
            'exceptions' => $exceptions,
            'accounts' => $rows,
        ];
    }

    /**
     * Prepare (or revise) the reconciliation for the month ending $periodEnd.
     *
     * @param  array{bank_account: string, statement_balance_cents: int, deposits_in_transit?: list<array{description: string, amount_cents: int}>, outstanding_checks?: list<array{description: string, amount_cents: int}>, notes?: ?string}  $data
     */
    public function prepare(CarbonImmutable $periodEnd, array $data, User $by): TrustReconciliation
    {
        $periodEnd = $periodEnd->endOfMonth()->startOfDay();
        if ($periodEnd->gte(CarbonImmutable::today())) {
            throw ValidationException::withMessages(['period_end' => 'A month can be reconciled once it has ended.']);
        }

        return DB::transaction(function () use ($periodEnd, $data, $by) {
            $reconciliation = TrustReconciliation::query()->whereDate('period_end', $periodEnd->toDateString())->lockForUpdate()->first();
            if ($reconciliation?->isSignedOff()) {
                throw ValidationException::withMessages(['period_end' => 'This month was signed off; it can no longer be changed.']);
            }

            $deposits = array_values($data['deposits_in_transit'] ?? []);
            $checks = array_values($data['outstanding_checks'] ?? []);
            $figures = $this->figures($periodEnd);

            $reconciliation ??= new TrustReconciliation(['firm_id' => $by->firm_id, 'period_end' => $periodEnd->toDateString()]);
            $reconciliation->fill([
                'bank_account' => $data['bank_account'],
                'statement_balance_cents' => $data['statement_balance_cents'],
                'deposits_in_transit' => $deposits,
                'outstanding_checks' => $checks,
                'notes' => $data['notes'] ?? null,
                'prepared_by' => $by->id,
            ])->forceFill([
                'adjusted_bank_cents' => $data['statement_balance_cents'] + array_sum(array_column($deposits, 'amount_cents')) - array_sum(array_column($checks, 'amount_cents')),
                'ledger_cents' => $figures['ledger_cents'],
                'client_total_cents' => $figures['client_total_cents'],
                'exceptions' => $figures['exceptions'],
            ])->save();

            return $reconciliation;
        });
    }

    /** A partner's sign-off. A reconciliation that does not balance needs an explanation. */
    public function signOff(TrustReconciliation $reconciliation, ?string $notes, User $by): TrustReconciliation
    {
        return DB::transaction(function () use ($reconciliation, $notes, $by) {
            $reconciliation = TrustReconciliation::whereKey($reconciliation->id)->lockForUpdate()->firstOrFail();
            if ($reconciliation->isSignedOff()) {
                throw ValidationException::withMessages(['reconciliation' => 'Already signed off.']);
            }
            // Re-checked at sign-off, in case postings were back-dated since it was prepared.
            $figures = $this->figures(CarbonImmutable::instance($reconciliation->period_end));
            $reconciliation->forceFill(['ledger_cents' => $figures['ledger_cents'], 'client_total_cents' => $figures['client_total_cents'], 'exceptions' => $figures['exceptions']]);

            $notes = trim((string) ($notes ?? $reconciliation->notes));
            if (! $reconciliation->balances() && $notes === '') {
                throw ValidationException::withMessages(['notes' => 'It does not balance: explain the difference and what is being done about it before signing off.']);
            }

            $reconciliation->forceFill(['notes' => $notes ?: null, 'signed_off_by' => $by->id, 'signed_off_at' => now()])->save();
            AuditLog::record('trust_reconciliation_signed_off', $reconciliation->firm_id, $by, $reconciliation, [
                'period_end' => $reconciliation->period_end->toDateString(),
                'difference' => $reconciliation->difference(),
                'balances' => $reconciliation->balances(),
            ]);

            return $reconciliation;
        });
    }

    /** On the due day: partners of firms holding trust funds whose last month is not signed off. */
    public function remind(?CarbonImmutable $today = null): int
    {
        $today ??= CarbonImmutable::today();
        if ($today->day !== self::DUE_DAY) {
            return 0;
        }
        $period = $today->subMonthNoOverflow()->endOfMonth()->startOfDay();
        $sent = 0;

        Firm::query()->pluck('id')->each(function (int $firmId) use ($period, &$sent) {
            app(TenantContext::class)->runAs($firmId, function () use ($period, &$sent) {
                $held = DB::table('trust_transactions')->whereIn('trust_account_id', TrustAccount::query()->select('id'))->where('created_at', '<=', $period->endOfDay())->exists();
                $done = TrustReconciliation::query()->whereDate('period_end', $period->toDateString())->whereNotNull('signed_off_at')->exists();
                if (! $held || $done) {
                    return;
                }
                User::query()->where('is_active', true)->whereIn('role', [Role::ManagingPartner->value, Role::Partner->value])->get()
                    ->each(function (User $partner) use ($period, &$sent) {
                        $partner->notify(new TrustReconciliationDue($period));
                        $sent++;
                    });
            });
        });

        return $sent;
    }

    private function peso(int $cents): string
    {
        return ($cents < 0 ? '−' : '').'₱'.number_format(abs($cents) / 100, 2);
    }
}
