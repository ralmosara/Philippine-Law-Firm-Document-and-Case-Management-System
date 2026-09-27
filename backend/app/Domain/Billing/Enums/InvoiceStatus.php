<?php

namespace App\Domain\Billing\Enums;

enum InvoiceStatus: string
{
    case Draft = 'draft';
    case Issued = 'issued';
    case Paid = 'paid';
    case Void = 'void';

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Draft => in_array($next, [self::Issued, self::Void], true),
            self::Issued => in_array($next, [self::Paid, self::Void], true),
            self::Paid, self::Void => false,
        };
    }
}
