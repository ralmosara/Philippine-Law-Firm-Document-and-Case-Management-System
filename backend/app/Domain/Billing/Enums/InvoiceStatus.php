<?php

namespace App\Domain\Billing\Enums;

enum InvoiceStatus: string
{
    case Draft = 'draft';
    case Issued = 'issued';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Void = 'void';
    /** The balance will not be collected; the firm wrote it off. */
    case WrittenOff = 'written_off';

    /**
     * Manual transitions. Issued, partially paid and paid also follow the
     * payments recorded (see InvoicePayments), including back to issued when
     * a payment is voided.
     */
    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Draft => in_array($next, [self::Issued, self::Void], true),
            self::Issued => in_array($next, [self::PartiallyPaid, self::Paid, self::Void, self::WrittenOff], true),
            self::PartiallyPaid => in_array($next, [self::Paid, self::WrittenOff], true),
            // Undoing a write-off puts the invoice back where its payments left it.
            self::WrittenOff => in_array($next, [self::Issued, self::PartiallyPaid], true),
            self::Paid, self::Void => false,
        };
    }

    /** Sent to the client and still owed in whole or in part. */
    public function isReceivable(): bool
    {
        return $this === self::Issued || $this === self::PartiallyPaid;
    }

    /** @return list<string> */
    public static function receivableValues(): array
    {
        return [self::Issued->value, self::PartiallyPaid->value];
    }
}
