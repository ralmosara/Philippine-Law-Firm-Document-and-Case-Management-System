<?php

namespace App\Domain\Documents\Search;

use Illuminate\Support\Facades\Process;
use RuntimeException;
use Smalot\PdfParser\Config as PdfConfig;
use Smalot\PdfParser\Parser as PdfParser;
use Symfony\Component\Process\ExecutableFinder;
use ZBateson\MailMimeParser\Header\AddressHeader;
use ZBateson\MailMimeParser\Header\HeaderConsts;
use ZBateson\MailMimeParser\MailMimeParser;
use ZipArchive;

/**
 * Pulls searchable text out of uploaded files. Returns null for formats it
 * cannot read (scanned images need OCR), which the caller records as
 * "unsupported".
 *
 * The old binary Office formats (.doc, .xls, .ppt) are read with catdoc,
 * and Outlook .msg files are converted to email with msgconvert, when those
 * tools are installed (they are in the Docker image); emails are read as
 * their headers and text, not the raw MIME with its encoded attachments.
 */
class FileTextExtractor
{
    /** Stored text is capped; beyond this, search sees the first part only. */
    public const MAX_CHARACTERS = 1_000_000;

    /** Legacy formats and the command-line tool each needs. */
    public const LEGACY_TOOLS = ['doc' => 'catdoc', 'xls' => 'xls2csv', 'ppt' => 'catppt', 'msg' => 'msgconvert'];

    /** @var array<string, bool> */
    private static array $found = [];

    public function supports(string $extension): bool
    {
        $extension = strtolower($extension);
        if (isset(self::LEGACY_TOOLS[$extension])) {
            return $this->hasTool(self::LEGACY_TOOLS[$extension]);
        }

        return in_array($extension, ['pdf', 'docx', 'pptx', 'xlsx', 'odt', 'ods', 'rtf', 'txt', 'csv', 'eml'], true);
    }

    /** Is a converter installed? (services.text_extraction.tools can say so, e.g. in tests.) */
    public function hasTool(string $tool): bool
    {
        $configured = config("services.text_extraction.tools.{$tool}");
        if ($configured !== null) {
            return (bool) $configured;
        }

        return self::$found[$tool] ??= (new ExecutableFinder)->find($tool) !== null;
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
            'txt', 'csv' => (string) file_get_contents($path),
            'eml' => $this->email((string) file_get_contents($path)),
            // -d utf-8: output encoding; catdoc -w: keep paragraphs on one line.
            'doc' => $this->run(['catdoc', '-d', 'utf-8', '-w', $path]),
            'xls' => $this->run(['xls2csv', '-d', 'utf-8', $path]),
            'ppt' => $this->run(['catppt', '-d', 'utf-8', $path]),
            'msg' => $this->outlook($path),
            default => null,
        };

        return $text === null ? null : $this->normalize($text);
    }

    /** An email as a person reads it: who, when, the subject and the text (attachments are filed and searched on their own). */
    private function email(string $raw): string
    {
        $message = (new MailMimeParser)->parse($raw, false);
        $lines = [];
        foreach ([HeaderConsts::SUBJECT, HeaderConsts::FROM, HeaderConsts::TO, HeaderConsts::CC, HeaderConsts::DATE] as $name) {
            $header = $message->getHeader($name);
            // Every address with its decoded name ("Ana Reyes <ana@example.ph>"), not only the first.
            $value = $header instanceof AddressHeader
                ? implode(', ', array_map(fn ($a) => trim($a->getName() !== '' ? "{$a->getName()} <{$a->getEmail()}>" : $a->getEmail()), $header->getAddresses()))
                : trim((string) $header?->getValue());
            if ($value !== '') {
                $lines[] = "{$name}: {$value}";
            }
        }
        $body = $message->getTextContent() ?? html_entity_decode(strip_tags((string) $message->getHtmlContent()), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return implode("\n", $lines)."\n\n".$body;
    }

    /** Outlook .msg → .eml (msgconvert), then read as an email. */
    private function outlook(string $path): string
    {
        $eml = tempnam(sys_get_temp_dir(), 'msg').'.eml';
        try {
            $this->run(['msgconvert', '--outfile', $eml, $path]);
            if (! is_file($eml) || filesize($eml) === 0) {
                throw new RuntimeException('The Outlook message could not be converted.');
            }

            return $this->email((string) file_get_contents($eml));
        } finally {
            @unlink($eml);
            @unlink(substr($eml, 0, -4));
        }
    }

    /** @param  list<string>  $command */
    private function run(array $command): string
    {
        $result = Process::timeout(120)->run($command);
        if (! $result->successful()) {
            throw new RuntimeException("{$command[0]} could not read the file: ".mb_substr(trim($result->errorOutput()), 0, 300));
        }

        return $result->output();
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
            throw new RuntimeException('Not a valid Office/OpenDocument file.');
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
