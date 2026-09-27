<?php

namespace App\Domain\Documents\Enums;

enum SignatureStatus: string
{
    case Pending = 'pending';
    case Signed = 'signed';
    case Declined = 'declined';
    case Cancelled = 'cancelled';
}
