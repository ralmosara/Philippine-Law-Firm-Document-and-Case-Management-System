<?php

namespace App\Domain\Deadlines\Enums;

enum DeadlineStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Missed = 'missed';
    case Cancelled = 'cancelled';

    public function isOpen(): bool
    {
        return $this === self::Pending;
    }
}
