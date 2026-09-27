<?php

namespace App\Domain\Documents\Scanning;

interface VirusScanner
{
    /**
     * Scan a local file before it is stored.
     *
     * @throws ScannerUnavailable when the scanner cannot give an answer
     */
    public function scan(string $path): ScanResult;
}
