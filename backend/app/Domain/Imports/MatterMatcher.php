<?php

namespace App\Domain\Imports;

use App\Domain\Imports\Importers\Importer;
use App\Domain\Matters\Models\Matter;
use Illuminate\Support\Collection;

/**
 * Matches a folder name from an archive to one of the firm's matters: by
 * its reference ("M-2026-0019"), a name that starts with the reference
 * ("M-2026-0019 Aquino v. Kalayaan"), or its case number.
 */
final class MatterMatcher
{
    /** @var Collection<int, Matter> */
    private Collection $matters;

    public function __construct()
    {
        $this->matters = Matter::query()->get(['id', 'reference', 'case_number']);
    }

    public function match(string $folder): ?Matter
    {
        $folder = trim($folder);
        if ($folder === '') {
            return null;
        }
        $token = preg_split('/[\s_]+/', $folder)[0] ?? '';
        $key = Importer::docketKey($folder);

        return $this->matters->first(fn (Matter $m) => strcasecmp($m->reference, $folder) === 0 || strcasecmp($m->reference, $token) === 0)
            ?? $this->matters->first(fn (Matter $m) => $m->case_number !== null && $key !== '' && Importer::docketKey($m->case_number) === $key);
    }
}
