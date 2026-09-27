<?php

namespace App\Domain\Compliance\Enums;

enum ConflictCheckStatus: string
{
    /** No matches: nothing to review. */
    case Clear = 'clear';

    /** Matches found and awaiting a lawyer's review. */
    case Flagged = 'flagged';

    /** Reviewed: matches are not a real conflict (or client gave informed consent). */
    case Waived = 'waived';

    /** Reviewed: a real conflict; the engagement must be declined. */
    case Declined = 'declined';
}
