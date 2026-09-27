<?php

namespace App\Domain\Trust\Exceptions;

use App\Domain\Trust\Models\TrustAccount;
use RuntimeException;

class InsufficientTrustFunds extends RuntimeException
{
    public function __construct(public readonly TrustAccount $account, public readonly int $requestedCents)
    {
        parent::__construct(sprintf(
            'Insufficient trust funds in %s: requested %s, available %s.',
            $account->account_number,
            number_format($requestedCents / 100, 2),
            number_format($account->balance_cents / 100, 2),
        ));
    }
}
