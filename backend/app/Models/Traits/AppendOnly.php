<?php

namespace App\Models\Traits;

use LogicException;

/**
 * For audit and ledger records: rows may be inserted but never changed or
 * removed through Eloquent. Corrections are new rows that reference the
 * row they correct.
 */
trait AppendOnly
{
    public static function bootAppendOnly(): void
    {
        static::updating(fn () => throw new LogicException(class_basename(static::class).' records are append-only.'));
        static::deleting(fn () => throw new LogicException(class_basename(static::class).' records are append-only.'));
    }
}
