<?php

namespace App\Domain\Billing\Enums;

enum ExpenseCategory: string
{
    case FilingFee = 'filing_fee';
    case SheriffFee = 'sheriff_fee';
    case Transcript = 'transcript';
    case Notarial = 'notarial';
    case Courier = 'courier';
    case Travel = 'travel';
    case Copying = 'copying';
    case Publication = 'publication';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::FilingFee => 'Docket and filing fees',
            self::SheriffFee => 'Sheriff’s and process server’s fees',
            self::Transcript => 'Transcript of stenographic notes (TSN)',
            self::Notarial => 'Notarial fees',
            self::Courier => 'Courier and postage',
            self::Travel => 'Transportation and travel',
            self::Copying => 'Photocopying and printing',
            self::Publication => 'Publication',
            self::Other => 'Other costs',
        };
    }
}
