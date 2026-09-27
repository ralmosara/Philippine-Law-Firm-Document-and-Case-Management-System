<?php

namespace App\Domain\Matters\Enums;

enum FeeArrangement: string
{
    case Hourly = 'hourly';
    case Flat = 'flat';
    case Retainer = 'retainer';
    case Contingency = 'contingency';
    case ProBono = 'pro_bono';

    public function label(): string
    {
        return match ($this) {
            self::Hourly => 'Hourly (time billed)',
            self::Flat => 'Flat fee',
            self::Retainer => 'Monthly retainer',
            self::Contingency => 'Contingency (share of recovery)',
            self::ProBono => 'Pro bono',
        };
    }
}
