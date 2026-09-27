<?php

namespace App\Domain\Trust\Services;

use App\Domain\Trust\Enums\TrustTransactionType;
use App\Domain\Trust\Exceptions\InsufficientTrustFunds;
use App\Domain\Trust\Models\TrustAccount;
use App\Domain\Trust\Models\TrustTransaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * The only writer of trust balances.
 *
 * Each transaction locks the account row, computes the new balance from the
 * locked value, appends a ledger row carrying that balance, and updates the
 * cached balance, all in one database transaction. Concurrent postings to
 * the same account are therefore serialised, and the ledger is never
 * observed half-written.
 */
class TrustLedgerService
{
    public function deposit(TrustAccount $account, int $amountCents, string $description, ?string $reference = null, ?User $by = null): TrustTransaction
    {
        return $this->record($account, TrustTransactionType::Deposit, $amountCents, $description, $reference, $by);
    }

    public function disburse(TrustAccount $account, int $amountCents, string $description, ?string $reference = null, ?User $by = null): TrustTransaction
    {
        return $this->record($account, TrustTransactionType::Disbursement, $amountCents, $description, $reference, $by);
    }

    public function record(TrustAccount $account, TrustTransactionType $type, int $amountCents, string $description, ?string $reference = null, ?User $by = null): TrustTransaction
    {
        if ($amountCents <= 0) {
            throw new InvalidArgumentException('Trust transaction amounts must be positive.');
        }

        return DB::transaction(function () use ($account, $type, $amountCents, $description, $reference, $by) {
            $locked = TrustAccount::withoutGlobalScopes()->lockForUpdate()->findOrFail($account->id);

            if (! $locked->isOpen()) {
                throw new LogicException("Trust account {$locked->account_number} is closed.");
            }

            $newBalance = $locked->balance_cents + $type->signedAmount($amountCents);

            if ($newBalance < 0) {
                throw new InsufficientTrustFunds($locked, $amountCents);
            }

            $transaction = $locked->transactions()->create([
                'type' => $type,
                'amount_cents' => $amountCents,
                'balance_after_cents' => $newBalance,
                'reference' => $reference,
                'description' => $description,
                'created_by' => $by?->id,
            ]);

            $locked->forceFill(['balance_cents' => $newBalance])->save();
            $account->setRawAttributes($locked->getAttributes(), true);

            return $transaction;
        });
    }

    /**
     * Recompute the balance from the full ledger and check every row's
     * running balance. Returns a list of problems; empty means reconciled.
     *
     * @return list<string>
     */
    public function reconcile(TrustAccount $account): array
    {
        $problems = [];
        $running = 0;

        TrustTransaction::query()
            ->where('trust_account_id', $account->id)
            ->orderBy('id')
            ->lazyById(500)
            ->each(function (TrustTransaction $tx) use (&$running, &$problems) {
                $running += $tx->type->signedAmount($tx->amount_cents);

                if ($tx->balance_after_cents !== $running) {
                    $problems[] = "Transaction #{$tx->id}: recorded balance {$tx->balance_after_cents}, expected {$running}.";
                }
                if ($running < 0) {
                    $problems[] = "Transaction #{$tx->id}: balance went negative ({$running}).";
                }
            });

        if ($account->balance_cents !== $running) {
            $problems[] = "Cached balance {$account->balance_cents} does not match ledger total {$running}.";
        }

        return $problems;
    }

    public function close(TrustAccount $account): void
    {
        if ($account->balance_cents !== 0) {
            throw new LogicException('Only a trust account with a zero balance can be closed; refund or apply the remaining funds first.');
        }

        $account->forceFill(['status' => 'closed'])->save();
    }
}
