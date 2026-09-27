<?php

namespace App\Domain\Documents\Enums;

enum DocumentStatus: string
{
    case Draft = 'draft';
    case Final = 'final';
    case PendingSignature = 'pending_signature';
    case Signed = 'signed';
    case Notarized = 'notarized';

    /** Once final, content is frozen; changes require a new document. */
    public function isEditable(): bool
    {
        return $this === self::Draft;
    }
}
