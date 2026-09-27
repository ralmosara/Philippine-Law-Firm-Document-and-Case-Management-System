<?php

namespace App\Domain\Documents\Search;

use Smalot\PdfParser\Config as PdfConfig;
use Smalot\PdfParser\Parser as PdfParser;
use ZipArchive;

/**
 * Pulls searchable text out of uploaded files. Returns null for formats it
 * cannot read (scanned images need OCR; legacy binary .doc/.xls/.msg need
 * external converters), which the caller records as "unsupported".
 */
class FileTextExtractor
{
    /** Stored text is capped; beyond this, search sees the first part only. */
    public const MAX_CHARACTERS = 1_000_000;

    public function supports(string $extension): bool
    {
        return in_array(strtolower($extension), ['pdf', 'docx', 'pptx', 'xlsx', 'odt', 'ods', 'rtf', 'txt', 'csv', 'eml'], true);
    }

    public function extract(string $path, string $extension): ?string
    {
        $text = match (strtolower($extension)) {
            'pdf' => $this->pdf($path),
            'docx' => $this->zipXml($path, ['word/document.xml', 'word/header*.xml', 'word/footer*.xml', 'word/footnotes.xml']),
            'pptx' => $this->zipXml($path, ['ppt/slides/slide*.xml', 'ppt/notesSlides/notesSlide*.xml']),
            'xlsx' => $this->zipXml($path, ['xl/sharedStrings.xml']),
            'odt', 'ods' => $this->zipXml($path, ['content.xml']),
            'rtf' => $this->rtf((string) file_get_contents($path)),
            'txt', 'csv', 'eml' => (string) file_get_contents($path),
            default => null,
        };

        return $text === null ? null : $this->normalize($text);
    }

    private function pdf(string $path): string
    {
        $config = new PdfConfig;
        $config->setRetainImageContent(false);
        $config->setDecodeMemoryLimit(64 * 1024 * 1024);

        return (new PdfParser([], $config))->parseFile($path)->getText();
    }

    /** @param  list<string>  $patterns  entry names, `*` allowed */
    private function zipXml(string $path, array $patterns): string
    {
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new \RuntimeException('Not a valid Office/OpenDocument file.');
        }

        try {
            $names = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $names[] = (string) $zip->getNameIndex($i);
            }
            natsort($names);

            $parts = [];
            foreach ($patterns as $pattern) {
                foreach ($names as $name) {
                    if (fnmatch($pattern, $name)) {
                        $parts[] = $this->xmlToText((string) $zip->getFromName($name));
                    }
                }
            }

            return implode("\n", $parts);
        } finally {
            $zip->close();
        }
    }

    private function xmlToText(string $xml): string
    {
        // Tabs and line breaks become spaces; paragraphs, rows and shared
        // strings become lines. Everything else is markup.
        $xml = preg_replace('#<(w:tab|w:br|text:tab|text:s|text:line-break)\b[^>]*/>#', ' ', $xml) ?? $xml;
        $xml = preg_replace('#</(w:p|a:p|text:p|text:h|si|table:table-row)>#', "\n", $xml) ?? $xml;

        return html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /** Good enough for search: drop control words and groups, keep the words. */
    private function rtf(string $rtf): string
    {
        $text = preg_replace('/\\\\\'([0-9a-f]{2})/i', '', $rtf) ?? $rtf;
        $text = preg_replace('/\\\\(par|line)\b ?/', "\n", $text) ?? $text;
        $text = preg_replace('/\{\\\\\*[^{}]*\}/', '', $text) ?? $text;
        $text = preg_replace('/\\\\[a-z]+-?\d* ?/i', '', $text) ?? $text;

        return str_replace(['{', '}', '\\'], '', $text);
    }

    /** Clean extracted or recognised text: UTF-8, no NULs, tidy whitespace, capped length. */
    public function normalize(string $text): string
    {
        if (! mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
        }

        $text = str_replace("\0", '', mb_scrub($text, 'UTF-8'));
        $text = preg_replace("/[ \t\x{00A0}]+/u", ' ', $text) ?? $text;
        $text = trim(preg_replace("/\s*\n\s*/u", "\n", $text) ?? $text);

        return mb_substr($text, 0, self::MAX_CHARACTERS);
    }
}
