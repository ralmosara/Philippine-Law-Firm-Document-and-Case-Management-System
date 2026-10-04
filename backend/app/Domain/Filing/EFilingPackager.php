<?php

namespace App\Domain\Filing;

use App\Domain\Documents\Actions\StoreMatterFile;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\MatterFile;
use App\Domain\Filing\Models\EFiling;
use App\Domain\Matters\Models\Firm;
use App\Domain\Matters\Models\Matter;
use App\Models\User;
use App\Support\Pdf\PdfRenderer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Puts a pleading and its annexes into one PDF for electronic filing:
 * the pleading on the firm's pleading paper, each annex after a labelled
 * separator page (Annex "A", "B", ...), bookmarks for every part, and the
 * pages numbered consecutively. Then checks it against what courts commonly
 * require (size, searchable text, one paper size) and stores it with the
 * matter's files.
 *
 * Merging and numbering use Ghostscript; page counts and the text check use
 * poppler (pdfinfo, pdffonts).
 */
class EFilingPackager
{
    /** Points tolerance when comparing page sizes. */
    private const SIZE_TOLERANCE = 6;

    /** Page width and height in points, by the firm's pleading paper. */
    private const PAPER_POINTS = ['folio' => [612, 936], 'a4' => [595, 842], 'letter' => [612, 792]];

    public function __construct(private readonly PdfRenderer $pdf, private readonly StoreMatterFile $store) {}

    /**
     * The pleading is $document (rendered in the court format) or, instead,
     * options.main_file: an uploaded PDF such as the signed copy.
     *
     * @param  list<array{file: MatterFile, description: ?string}>  $annexes  in filing order
     * @param  array{title: string, annex_style?: string, separators?: bool, main_file?: ?MatterFile}  $options
     */
    public function build(Matter $matter, ?Document $document, array $annexes, User $by, array $options): EFiling
    {
        $mainFile = $options['main_file'] ?? null;
        if (! $document && ! $mainFile && $annexes === []) {
            throw ValidationException::withMessages(['document_id' => 'Choose the pleading, annexes, or both.']);
        }
        $firm = Firm::findOrFail($matter->firm_id);
        $paper = $firm->pleading_paper ?: 'folio';
        $dir = storage_path('app/tmp/efiling-'.Str::uuid());
        File::ensureDirectoryExists($dir);

        try {
            $parts = [];   // [path, bookmark, item]
            $checks = [];

            if ($mainFile) {
                $path = $this->annexPdf($mainFile, 'The pleading', "{$dir}/00-pleading", $paper);
                $parts[] = [$path, pathinfo($mainFile->original_name, PATHINFO_FILENAME), ['kind' => 'file', 'id' => $mainFile->id, 'label' => 'Pleading', 'name' => $mainFile->original_name]];
            } elseif ($document) {
                $path = "{$dir}/00-pleading.pdf";
                // The court format: fixed-width text with the Efficient Use of Paper Rule margins.
                file_put_contents($path, $this->pdf->render('pdf.pleading', [
                    'title' => $document->title,
                    'content' => (string) $document->latestVersion?->content,
                    'fontSize' => min($firm->pleading_font_size ?: 12, 12),
                ], $paper));
                $parts[] = [$path, $document->title, ['kind' => 'document', 'id' => $document->id, 'label' => $document->title, 'name' => $document->title]];
                if ($document->status->value === 'draft') {
                    $checks[] = ['level' => 'warning', 'message' => 'The pleading is still a draft. Mark it final once it is the version you will file.'];
                }
            }

            foreach (array_values($annexes) as $i => $annex) {
                $label = 'Annex "'.$this->annexLabel($i, $options['annex_style'] ?? 'letters').'"';
                $source = $this->annexPdf($annex['file'], $label, "{$dir}/".sprintf('%02d', $i + 1), $paper);
                if ($options['separators'] ?? true) {
                    $sep = "{$dir}/".sprintf('%02d', $i + 1).'-separator.pdf';
                    file_put_contents($sep, $this->pdf->render('pdf.annex-separator', [
                        'label' => $label, 'description' => $annex['description'], 'matter' => trim("{$matter->reference} · {$matter->title}"),
                    ], $paper));
                    $parts[] = [$sep, null, null];
                }
                $parts[] = [$source, trim($label.($annex['description'] ? " – {$annex['description']}" : '')), [
                    'kind' => 'file', 'id' => $annex['file']->id, 'label' => $label, 'name' => $annex['file']->original_name, 'description' => $annex['description'],
                ]];
                if (! $this->hasText($source) && $annex['file']->mime_type === 'application/pdf') {
                    $checks[] = ['level' => 'warning', 'message' => "{$label} ({$annex['file']->original_name}) is a scan without a text layer. Courts that require searchable PDFs may refuse it; run it through OCR software that adds a text layer."];
                }
            }

            // Page numbers, bookmarks and the paper check need each part's pages.
            $page = 1;
            $sizes = [];
            $marks = [];
            $items = [];
            foreach ($parts as [$path, $bookmark, $item]) {
                [$pages, $size] = $this->info($path);
                if ($bookmark !== null) {
                    $marks[] = '[/Title '.$this->psText($bookmark)." /Page {$page} /OUT pdfmark";
                }
                if ($item !== null) {
                    $items[] = [...$item, 'first_page' => $page, 'pages' => $pages];
                }
                if ($size) {
                    $sizes[] = $size;
                }
                $page += $pages;
            }
            $total = $page - 1;
            $marks[] = '[/Title '.$this->psText($options['title']).' /Creator (Lex PH) /DOCINFO pdfmark';
            file_put_contents("{$dir}/marks.ps", implode("\n", $marks)."\n");
            file_put_contents("{$dir}/numbering.ps", $this->numbering($total));

            [$w, $h] = self::PAPER_POINTS[$paper] ?? self::PAPER_POINTS['folio'];
            $odd = collect($sizes)->reject(fn ($s) => abs($s[0] - $w) <= self::SIZE_TOLERANCE && abs($s[1] - $h) <= self::SIZE_TOLERANCE)->count();
            if ($odd > 0) {
                $checks[] = ['level' => 'warning', 'message' => "{$odd} of the parts are not on the firm's pleading paper (".str_replace('folio', '8.5 x 13 in', $paper).'). Some courts require one paper size throughout.'];
            }

            $limit = (int) config('services.efiling.max_mb', 25) * 1024 * 1024;
            $output = "{$dir}/package.pdf";
            $this->merge($parts, $dir, $output, '/printer');
            if (filesize($output) > $limit) {
                // Scans are usually what makes a package heavy: try again at 150 dpi.
                $this->merge($parts, $dir, $output, '/ebook');
                $checks[] = ['level' => 'warning', 'message' => 'Images were reduced to 150 dpi to fit the size limit. Check that scans are still readable.'];
            }
            $size = filesize($output);
            if ($size > $limit) {
                $checks[] = ['level' => 'error', 'message' => sprintf('The package is %.1f MB; the limit is %d MB. File the annexes in separate parts, or rescan them at a lower resolution.', $size / 1048576, $limit / 1048576)];
            }
            array_unshift($checks, ['level' => 'ok', 'message' => sprintf('%d page%s, %.1f MB, numbered and bookmarked.', $total, $total === 1 ? '' : 's', $size / 1048576)]);

            $name = Str::limit(preg_replace('/[^\pL\pN ._()-]+/u', ' ', $options['title']) ?: 'Filing', 140, '').' (e-filing).pdf';
            $file = $this->store->execute($matter, new UploadedFile($output, $name, 'application/pdf', null, true), $by, 'E-filing package: '.$options['title']);

            return EFiling::create([
                'firm_id' => $matter->firm_id,
                'matter_id' => $matter->id,
                'document_id' => $document?->id,
                'title' => $options['title'],
                'items' => $items,
                'package_file_id' => $file->id,
                'page_count' => $total,
                'size_bytes' => $size,
                'checks' => $checks,
                'created_by' => $by->id,
            ]);
        } finally {
            File::deleteDirectory($dir);
        }
    }

    /** A matter file as a PDF in the work folder: PDFs as they are, images on a page of their own. */
    private function annexPdf(MatterFile $file, string $label, string $base, string $paper): string
    {
        $bytes = Storage::disk(MatterFile::disk())->get($file->path);
        if ($bytes === null) {
            throw ValidationException::withMessages(['annexes' => "{$label} ({$file->original_name}) could not be read from storage."]);
        }
        if ($file->mime_type === 'application/pdf') {
            file_put_contents("{$base}.pdf", $bytes);

            return "{$base}.pdf";
        }
        if (in_array($file->mime_type, ['image/jpeg', 'image/png'], true)) {
            file_put_contents("{$base}.pdf", $this->pdf->render('pdf.annex-image', ['src' => "data:{$file->mime_type};base64,".base64_encode($bytes)], $paper));

            return "{$base}.pdf";
        }

        throw ValidationException::withMessages(['annexes' => "{$label} ({$file->original_name}) is not a PDF or a JPG/PNG image. Save it as PDF first."]);
    }

    private function annexLabel(int $index, string $style): string
    {
        if ($style === 'numbers') {
            return (string) ($index + 1);
        }

        // A..Z, then AA, BB, ... as in exhibit marking.
        return str_repeat(chr(65 + $index % 26), intdiv($index, 26) + 1);
    }

    /** @return array{0: int, 1: array{0: float, 1: float}|null} pages, and the first page's size in points */
    private function info(string $path): array
    {
        $result = Process::timeout(60)->run(['pdfinfo', $path]);
        if (! $result->successful()) {
            throw ValidationException::withMessages(['annexes' => 'A file could not be read as a PDF: '.Str::limit(trim($result->errorOutput()), 200)]);
        }
        $out = $result->output();
        preg_match('/^Pages:\s+(\d+)/m', $out, $pages);
        preg_match('/^Page size:\s+([\d.]+) x ([\d.]+) pts/m', $out, $size);

        return [(int) ($pages[1] ?? 1), isset($size[1]) ? [(float) $size[1], (float) $size[2]] : null];
    }

    /** Whether a PDF has any text (fonts), or is only scanned images. */
    private function hasText(string $path): bool
    {
        $result = Process::timeout(60)->run(['pdffonts', $path]);

        // Two header lines, then one line per font.
        return ! $result->successful() || count(array_filter(explode("\n", trim($result->output())))) > 2;
    }

    /** @param  list<array{0: string, 1: ?string, 2: ?array}>  $parts */
    private function merge(array $parts, string $dir, string $output, string $quality): void
    {
        $result = Process::timeout(300)->run([
            'gs', '-q', '-dBATCH', '-dNOPAUSE', '-dSAFER', '-sDEVICE=pdfwrite', '-dCompatibilityLevel=1.6',
            "-dPDFSETTINGS={$quality}", '-dAutoRotatePages=/None', "-sOutputFile={$output}",
            "{$dir}/numbering.ps", ...array_column($parts, 0), "{$dir}/marks.ps",
        ]);
        if (! $result->successful() || ! is_file($output)) {
            throw new \RuntimeException('Ghostscript could not build the package: '.Str::limit(trim($result->errorOutput() ?: $result->output()), 500));
        }
    }

    /** PostScript that prints "Page n of N" at the foot of every page as it is written. */
    private function numbering(int $total): string
    {
        return <<<PS
            /LexPHPageNumber {
              /lexph-n exch 1 add 12 string cvs def
              gsave initgraphics
              /Helvetica findfont 9 scalefont setfont
              currentpagedevice /PageSize get 0 get 2 div
              (Page ) stringwidth pop lexph-n stringwidth pop add ( of {$total}) stringwidth pop add 2 div sub
              20 moveto (Page ) show lexph-n show ( of {$total}) show
              grestore
            } bind def
            << /EndPage { 0 eq { LexPHPageNumber true } { pop false } ifelse } bind >> setpagedevice
            PS;
    }

    /** Any text as a PostScript string: UTF-16 hex, so accents, quotes and parentheses survive. */
    private function psText(string $text): string
    {
        return '<FEFF'.strtoupper(bin2hex(mb_convert_encoding(Str::limit($text, 200, ''), 'UTF-16BE', 'UTF-8'))).'>';
    }
}
