<?php

namespace App\Domain\Documents\Scanning;

/** Used when CLAMAV_ENABLED is off: files are stored and marked "not scanned". */
class NullVirusScanner implements VirusScanner
{
    public function scan(string $path): ScanResult
    {
        return ScanResult::notScanned();
    }
}
