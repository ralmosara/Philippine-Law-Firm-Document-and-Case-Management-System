<?php

namespace App\Domain\Imports;

use Illuminate\Validation\ValidationException;
use XMLReader;
use ZipArchive;

/**
 * Reads the first sheet of a CSV or Excel (.xlsx) file into rows keyed by
 * the header row. Values come back as trimmed strings; Excel dates come
 * back as serial numbers, which Values::date() understands.
 */
class SpreadsheetReader
{
    public const MAX_ROWS = 5000;

    /** @return list<array<string, string>> header => value, one per data row */
    public function read(string $path, string $originalName): array
    {
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        $rows = match ($extension) {
            'csv', 'txt' => $this->csv($path),
            'xlsx' => $this->xlsx($path),
            default => throw ValidationException::withMessages(['file' => 'Upload a .csv or .xlsx file. For an older .xls file, save it as .xlsx in Excel first.']),
        };

        $rows = array_values(array_filter($rows, fn (array $row) => implode('', $row) !== ''));
        if ($rows === []) {
            throw ValidationException::withMessages(['file' => 'The file is empty.']);
        }

        $headers = array_map(fn ($h) => trim((string) $h), array_shift($rows));
        if (count($rows) > self::MAX_ROWS) {
            throw ValidationException::withMessages(['file' => 'At most '.number_format(self::MAX_ROWS).' rows per file. Split it into smaller files.']);
        }

        return array_map(function (array $row) use ($headers) {
            $assoc = [];
            foreach ($headers as $i => $header) {
                if ($header !== '') {
                    $assoc[$header] = trim((string) ($row[$i] ?? ''));
                }
            }

            return $assoc;
        }, $rows);
    }

    /** @return list<list<string>> */
    private function csv(string $path): array
    {
        $content = (string) file_get_contents($path);
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content); // Excel's UTF-8 BOM

        // Excel on Windows may save "CSV" as Windows-1252.
        if (! mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }

        // Semicolon-separated when the header line has more of those than commas.
        $firstLine = strtok($content, "\n") ?: '';
        $delimiter = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $content);
        rewind($handle);

        $rows = [];
        while (($row = fgetcsv($handle, null, $delimiter, '"', '')) !== false) {
            $rows[] = array_map(fn ($v) => (string) $v, $row);
        }
        fclose($handle);

        return $rows;
    }

    /** @return list<list<string>> */
    private function xlsx(string $path): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw ValidationException::withMessages(['file' => 'This .xlsx file could not be opened. Is it password-protected?']);
        }

        try {
            $shared = $this->sharedStrings($zip);
            $sheet = $this->firstSheetPath($zip);
            $xml = $zip->getFromName($sheet);
            if ($xml === false) {
                throw ValidationException::withMessages(['file' => 'The workbook has no worksheet.']);
            }

            return $this->sheetRows($xml, $shared);
        } finally {
            $zip->close();
        }
    }

    /** @return list<string> */
    private function sharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) {
            return [];
        }

        $strings = [];
        $reader = new XMLReader;
        $reader->XML($xml, null, LIBXML_NONET | LIBXML_COMPACT);
        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'si') {
                // Rich text splits a string into runs; the text of the whole <si> is the value.
                $strings[] = $this->textOf($reader->readOuterXml());
            }
        }

        return $strings;
    }

    private function textOf(string $xml): string
    {
        return html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private function firstSheetPath(ZipArchive $zip): string
    {
        $workbook = $zip->getFromName('xl/workbook.xml');
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');

        if ($workbook !== false && $rels !== false
            && preg_match('/<sheet\b[^>]*\br:id="([^"]+)"/', $workbook, $sheet)
            && preg_match('/<Relationship\b[^>]*\bId="'.preg_quote($sheet[1], '/').'"[^>]*\bTarget="([^"]+)"/', $rels, $target)) {
            $path = ltrim($target[1], '/');

            return str_starts_with($path, 'xl/') ? $path : 'xl/'.$path;
        }

        return 'xl/worksheets/sheet1.xml';
    }

    /**
     * @param  list<string>  $shared
     * @return list<list<string>>
     */
    private function sheetRows(string $xml, array $shared): array
    {
        $rows = [];
        $reader = new XMLReader;
        $reader->XML($xml, null, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE);

        $row = null;
        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'row') {
                $row = [];
                if ($reader->isEmptyElement) {
                    $rows[] = [];
                    $row = null;
                }
            } elseif ($reader->nodeType === XMLReader::END_ELEMENT && $reader->localName === 'row') {
                $rows[] = $row ?? [];
                $row = null;
            } elseif ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'c' && $row !== null) {
                $ref = (string) $reader->getAttribute('r');
                $type = (string) $reader->getAttribute('t');
                $cell = $reader->readOuterXml();

                $value = match ($type) {
                    's' => $shared[(int) $this->between($cell, '<v>', '</v>')] ?? '',
                    'inlineStr' => $this->textOf((string) $this->between($cell, '<is>', '</is>')),
                    'b' => $this->between($cell, '<v>', '</v>') === '1' ? 'TRUE' : 'FALSE',
                    default => html_entity_decode((string) $this->between($cell, '<v>', '</v>'), ENT_QUOTES | ENT_XML1, 'UTF-8'),
                };

                $column = $ref !== '' ? $this->columnIndex($ref) : count($row);
                $row = array_pad($row, $column, '');
                $row[$column] = $value;
            }
        }

        return $rows;
    }

    private function between(string $haystack, string $start, string $end): ?string
    {
        $from = strpos($haystack, $start);
        if ($from === false) {
            return null;
        }
        $from += strlen($start);
        $to = strpos($haystack, $end, $from);

        return $to === false ? null : substr($haystack, $from, $to - $from);
    }

    /** "C7" => 2 */
    private function columnIndex(string $ref): int
    {
        $letters = preg_replace('/\d+/', '', strtoupper($ref));
        $index = 0;
        foreach (str_split($letters) as $letter) {
            $index = $index * 26 + (ord($letter) - 64);
        }

        return $index - 1;
    }
}
