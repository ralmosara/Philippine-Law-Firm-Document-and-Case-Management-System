<?php

namespace App\Domain\Documents\Scanning;

final class ScanResult
{
    public const CLEAN = 'clean';

    public const INFECTED = 'infected';

    /** Scanning is turned off for this installation. */
    public const NOT_SCANNED = 'not_scanned';

    private function __construct(
        public readonly string $status,
        public readonly ?string $signature = null,
    ) {}

    public static function clean(): self
    {
        return new self(self::CLEAN);
    }

    public static function infected(string $signature): self
    {
        return new self(self::INFECTED, $signature);
    }

    public static function notScanned(): self
    {
        return new self(self::NOT_SCANNED);
    }

    public function isInfected(): bool
    {
        return $this->status === self::INFECTED;
    }
}
