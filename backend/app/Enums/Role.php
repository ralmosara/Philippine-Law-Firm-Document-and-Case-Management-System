<?php

namespace App\Enums;

/**
 * Staff roles within a firm. Authorization is expressed through policies that
 * ask capability questions of the role (e.g. canManageFirm) rather than
 * comparing role names inline, so adding a role is a one-file change.
 */
enum Role: string
{
    case ManagingPartner = 'managing_partner';
    case Partner = 'partner';
    case Associate = 'associate';
    case Paralegal = 'paralegal';
    case Staff = 'staff';

    public function label(): string
    {
        return match ($this) {
            self::ManagingPartner => 'Managing Partner',
            self::Partner => 'Partner',
            self::Associate => 'Associate',
            self::Paralegal => 'Paralegal',
            self::Staff => 'Staff',
        };
    }

    /** Users, firm settings, holidays, deadline rules. */
    public function canManageFirm(): bool
    {
        return $this === self::ManagingPartner;
    }

    /** Firm-wide financials: analytics, invoices, trust disbursements. */
    public function canManageFinances(): bool
    {
        return in_array($this, [self::ManagingPartner, self::Partner], true);
    }

    /** Licensed lawyers: can be counsel of record, notarize, earn MCLE credits. */
    public function isLawyer(): bool
    {
        return in_array($this, [self::ManagingPartner, self::Partner, self::Associate], true);
    }

    /** Anyone who does substantive matter work (not purely administrative staff). */
    public function canWorkMatters(): bool
    {
        return $this !== self::Staff;
    }
}
