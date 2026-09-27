<?php

namespace App\Domain\Trust\Enums;

enum TrustTransactionType: string
{
    case Deposit = 'deposit';
    case Disbursement = 'disbursement';

    /** Signed effect of a transaction of this type on the running balance. */
    public function signedAmount(int $amountCents): int
    {
        return $this === self::Deposit ? $amountCents : -$amountCents;
    }
}
