<?php

namespace App\Domain\Matters\Enums;

enum PartyRole: string
{
    case AdverseParty = 'adverse_party';
    case AdverseCounsel = 'adverse_counsel';
    case CoParty = 'co_party';
    case Witness = 'witness';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::AdverseParty => 'Adverse Party',
            self::AdverseCounsel => 'Adverse Counsel',
            self::CoParty => 'Co-Party',
            self::Witness => 'Witness',
            self::Other => 'Other',
        };
    }

    /** Parties whose interests are opposed to the firm's client. */
    public function isAdverse(): bool
    {
        return in_array($this, [self::AdverseParty, self::AdverseCounsel], true);
    }
}
