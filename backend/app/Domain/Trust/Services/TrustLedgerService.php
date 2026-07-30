<?php

namespace App\Domain\Trust\Services;

use Illuminate\Support\Facades\DB;
use Exception;

class TrustLedgerService
{
    /**
     * Deposit funds into a trust account.
     *
     * @param int $trustAccountId
     * @param int $amountCents
     * @param string $referenceNumber
     * @return void
     * @throws Exception
     */
    public function deposit(int $trustAccountId, int $amountCents, string $referenceNumber = null): void
    {
        if ($amountCents <= 0) {
            throw new Exception("Deposit amount must be strictly positive.");
        }

        DB::table('trust_transactions')->insert([
            'trust_account_id' => $trustAccountId,
            'amount_cents' => $amountCents, // Positive
            'transaction_type' => 'deposit',
            'reference_number' => $referenceNumber,
            'created_at' => now(),
        ]);
        
        // Note: For SQLite testing where the trigger doesn't exist, we must manually update the balance
        // In a real Postgres environment, the trigger handles this atomically.
        if (DB::getDriverName() !== 'pgsql') {
            $this->fallbackUpdateBalance($trustAccountId, $amountCents);
        }
    }

    /**
     * Withdraw funds from a trust account.
     *
     * @param int $trustAccountId
     * @param int $amountCents
     * @param string $referenceNumber
     * @return void
     * @throws Exception
     */
    public function withdraw(int $trustAccountId, int $amountCents, string $referenceNumber = null): void
    {
        if ($amountCents <= 0) {
            throw new Exception("Withdrawal amount must be strictly positive.");
        }

        DB::transaction(function () use ($trustAccountId, $amountCents, $referenceNumber) {
            
            if (DB::getDriverName() !== 'pgsql') {
                 // Manual check for SQLite fallback
                 $currentBalance = DB::table('trust_accounts')->where('id', $trustAccountId)->value('current_balance_cents');
                 if ($currentBalance < $amountCents) {
                     throw new Exception("Insufficient trust funds. Attempted to withdraw {$amountCents}, but balance is {$currentBalance}");
                 }
            }

            DB::table('trust_transactions')->insert([
                'trust_account_id' => $trustAccountId,
                'amount_cents' => -$amountCents, // Negative
                'transaction_type' => 'withdrawal',
                'reference_number' => $referenceNumber,
                'created_at' => now(),
            ]);
            
            if (DB::getDriverName() !== 'pgsql') {
                $this->fallbackUpdateBalance($trustAccountId, -$amountCents);
            }
        });
    }
    
    private function fallbackUpdateBalance(int $trustAccountId, int $amountCents)
    {
        $currentBalance = DB::table('trust_accounts')->where('id', $trustAccountId)->value('current_balance_cents');
        $newBalance = $currentBalance + $amountCents;
        
        // Emulate the trigger's behavior
        $latestTxId = DB::table('trust_transactions')
            ->where('trust_account_id', $trustAccountId)
            ->max('id');
            
        DB::table('trust_transactions')
            ->where('id', $latestTxId)
            ->update(['balance_after_cents' => $newBalance]);
            
        DB::table('trust_accounts')
            ->where('id', $trustAccountId)
            ->update(['current_balance_cents' => $newBalance, 'updated_at' => now()]);
    }
}
