<?php

namespace App\Domain\Documents\Pleadings;

use RuntimeException;
use ZipArchive;

/**
 * Writes a plain-text document as a Word (.docx) file with the page format
 * courts expect, without extra libraries. The text's own layout is kept:
 * - a line centered with spaces becomes a centered paragraph (bold if it is
 *   all capitals, as headings and titles are);
 * - a line with a second column far to the right (the caption's docket
 *   number beside the parties) becomes a borderless two-column table row;
 * - leading spaces become an indent (signature blocks);
 * - blank lines stay blank lines, so paragraphs keep their spacing.
 */
class DocxWriter
{
    /** Page sizes in twentieths of a point. */
    public const PAPERS = [
        'folio' => ['label' => '8.5 x 13 in (long bond)', 'w' => 12240, 'h' => 18720],
        'a4' => ['label' => 'A4', 'w' => 11906, 'h' => 16838],
        'letter' => ['label' => 'Letter (8.5 x 11 in)', 'w' => 12240, 'h' => 15840],
    ];

    /** Width the plain text assumes when centering with spaces. */
    private const TEXT_WIDTH = 78;

    /**
     * @param  array{paper?: string, font?: string, size?: int, title?: string}  $format
     */
    public function write(string $text, array $format = []): string
    {
        $paper = self::PAPERS[$format['paper'] ?? 'folio'] ?? self::PAPERS['folio'];
        $font = $this->xml($format['font'] ?? 'Times New Roman');
        $halfPoints = (int) (($format['size'] ?? 14) * 2);

        $body = '';
        $table = [];
        foreach (preg_split('/\R/', rtrim($text)) as $line) {
            $line = rtrim(str_replace("\t", '    ', $line));
            $columns = $this->columns($line);

            if ($columns !== null) {
                $table[] = $columns;

                continue;
            }
            if ($table !== []) {
                $body .= $this->table($table, $paper['w']);
                $table = [];
            }
            $body .= $this->paragraph($line);
        }
        if ($table !== []) {
            $body .= $this->table($table, $paper['w']);
        }

        // Efficient Use of Paper Rule margins: left 1.5 in, top 1.2 in, right and bottom 1 in.
        $section = '<w:sectPr><w:pgSz w:w="'.$paper['w'].'" w:h="'.$paper['h'].'"/>'
            .'<w:pgMar w:top="1728" w:right="1440" w:bottom="1440" w:left="2160" w:header="720" w:footer="720" w:gutter="0"/></w:sectPr>';

        $document = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'.$body.$section.'</w:body></w:document>';

        $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            .'<w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="'.$font.'" w:hAnsi="'.$font.'" w:cs="'.$font.'" w:eastAsia="'.$font.'"/>'
            .'<w:sz w:val="'.$halfPoints.'"/><w:szCs w:val="'.$halfPoints.'"/><w:lang w:val="en-PH"/></w:rPr></w:rPrDefault>'
            .'<w:pPrDefault><w:pPr><w:spacing w:after="0" w:line="240" w:lineRule="auto"/><w:jc w:val="both"/></w:pPr></w:pPrDefault></w:docDefaults>'
            .'<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/></w:style>'
            .'<w:style w:type="table" w:default="1" w:styleId="TableNormal"><w:name w:val="Normal Table"/><w:tblPr><w:tblCellMar><w:left w:w="0" w:type="dxa"/><w:right w:w="0" w:type="dxa"/></w:tblCellMar></w:tblPr></w:style>'
            .'</w:styles>';

        $title = $this->xml($format['title'] ?? 'Pleading');
        $core = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            .'<dc:title>'.$title.'</dc:title><dcterms:created xsi:type="dcterms:W3CDTF">'.gmdate('Y-m-d\TH:i:s\Z').'</dcterms:created></cp:coreProperties>';

        return $this->zip([
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                .'<Default Extension="xml" ContentType="application/xml"/>'
                .'<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
                .'<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
                .'<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
                .'</Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
                .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
                .'</Relationships>',
            'word/_rels/document.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
                .'</Relationships>',
            'word/document.xml' => $document,
            'word/styles.xml' => $styles,
            'docProps/core.xml' => $core,
        ]);
    }

    /** "LEFT TEXT            RIGHT TEXT" (a gap of 6+ spaces after text) => [left, right]. */
    private function columns(string $line): ?array
    {
        // The left side may itself be indented (the caption's "Plaintiff," line).
        if (! preg_match('/^( *\S.*?\S| *\S) {6,}(\S.*)$/u', $line, $m)) {
            return null;
        }

        return [$m[1], $m[2]];
    }

    private function paragraph(string $line): string
    {
        if (trim($line) === '') {
            return '<w:p/>';
        }

        $lead = strlen($line) - strlen(ltrim($line, ' '));
        $text = ltrim($line, ' ');
        $length = mb_strlen($text);
        $centered = $lead > 0 && abs($lead - (self::TEXT_WIDTH - $lead - $length)) <= 2;
        $capitals = $text === mb_strtoupper($text) && preg_match('/\p{Lu}/u', $text);

        $pPr = match (true) {
            $centered => '<w:jc w:val="center"/>',
            $lead > 0 => '<w:ind w:left="'.min($lead * 110, 6000).'"/><w:jc w:val="left"/>',
            default => '',
        };
        $rPr = $centered && $capitals ? '<w:rPr><w:b/></w:rPr>' : '';

        return '<w:p>'.($pPr ? "<w:pPr>{$pPr}</w:pPr>" : '').'<w:r>'.$rPr.'<w:t xml:space="preserve">'.$this->xml($text).'</w:t></w:r></w:p>';
    }

    /** @param  list<array{0: string, 1: string}>  $rows */
    private function table(array $rows, int $pageWidth): string
    {
        $usable = $pageWidth - 2160 - 1440;
        $leftWidth = (int) round($usable * 0.55);
        $rightWidth = $usable - $leftWidth;
        $none = '<w:top w:val="nil"/><w:left w:val="nil"/><w:bottom w:val="nil"/><w:right w:val="nil"/><w:insideH w:val="nil"/><w:insideV w:val="nil"/>';

        $xml = '<w:tbl><w:tblPr><w:tblW w:w="'.$usable.'" w:type="dxa"/><w:tblBorders>'.$none.'</w:tblBorders><w:tblLayout w:type="fixed"/></w:tblPr>'
            .'<w:tblGrid><w:gridCol w:w="'.$leftWidth.'"/><w:gridCol w:w="'.$rightWidth.'"/></w:tblGrid>';
        foreach ($rows as [$left, $right]) {
            $xml .= '<w:tr>'
                .'<w:tc><w:tcPr><w:tcW w:w="'.$leftWidth.'" w:type="dxa"/></w:tcPr>'.$this->cell($left).'</w:tc>'
                .'<w:tc><w:tcPr><w:tcW w:w="'.$rightWidth.'" w:type="dxa"/></w:tcPr>'.$this->cell($right).'</w:tc>'
                .'</w:tr>';
        }

        return $xml.'</w:tbl>';
    }

    private function cell(string $text): string
    {
        return '<w:p><w:pPr><w:jc w:val="left"/></w:pPr><w:r><w:t xml:space="preserve">'.$this->xml($text).'</w:t></w:r></w:p>';
    }

    /** @param  array<string, string>  $files */
    private function zip(array $files): string
    {
        $path = tempnam(sys_get_temp_dir(), 'docx');
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create the Word file.');
        }
        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }

    private function xml(string $text): string
    {
        // Strip characters XML 1.0 cannot carry (control characters pasted from elsewhere).
        return htmlspecialchars((string) preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', $text), ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
