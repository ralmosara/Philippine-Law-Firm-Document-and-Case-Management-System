<?php

namespace App\Support\Pdf;

use Barryvdh\DomPDF\Facade\Pdf;
use Dompdf\Dompdf;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * Renders Blade views to PDF with Dompdf. A4, the size required for court
 * submissions under the Efficient Use of Paper Rule. Remote resources and
 * embedded PHP stay disabled, so user-written content cannot fetch URLs or
 * run code while rendering.
 */
class PdfRenderer
{
    /** Court paper sizes in points ([x, y, width, height]), by the firm's pleading_paper setting. */
    public const PAPERS = [
        'folio' => [0, 0, 612, 936],          // 8.5 x 13 in (long bond)
        'a4' => [0, 0, 595.28, 841.89],
        'letter' => [0, 0, 612, 792],
        // Wide tables such as the books of accounts.
        'a4-landscape' => [0, 0, 841.89, 595.28],
    ];

    /** The shared layout (pdf.layout) asks for "Page n of N" in its footer with this tag. */
    public const PAGE_NUMBERS_MARKER = '<meta name="page-numbers" content="footer">';

    public function render(string $view, array $data, string $paper = 'a4'): string
    {
        $html = view($view, $data)->render();
        $pdf = Pdf::setOption([
            'isRemoteEnabled' => false,
            'isPhpEnabled' => false,
            'isJavascriptEnabled' => false,
            'defaultFont' => 'DejaVu Sans',
            'dpi' => 96,
        ])
            ->loadHTML($html)
            ->setPaper(self::PAPERS[$paper] ?? 'a4');

        if (str_contains($html, self::PAGE_NUMBERS_MARKER)) {
            // Laid out and numbered here; the wrapper's output() would lay it out again.
            $dompdf = $pdf->getDomPDF();
            $this->numberPages($dompdf);

            return (string) $dompdf->output();
        }

        return $pdf->output();
    }

    /**
     * Dompdf cannot print the page total from CSS (counter(pages) comes out
     * as 0), so the footer's "Page n of N" is drawn on each page once the
     * document is laid out, right-aligned where the layout's footer sits.
     */
    private function numberPages(Dompdf $dompdf): void
    {
        $dompdf->render();
        $canvas = $dompdf->getCanvas();
        $metrics = $dompdf->getFontMetrics();
        $font = $metrics->getFont('DejaVu Sans');
        $size = 7.5;
        $count = $canvas->get_page_count();
        $width = $metrics->getTextWidth("Page {$count} of {$count}", $font, $size);
        $mm = 72 / 25.4;

        // pdf.layout: 20 mm right margin; the footer's text sits about 8 mm above the bottom edge.
        $canvas->page_text(
            $canvas->get_width() - 20 * $mm - $width,
            $canvas->get_height() - 8 * $mm - $size * 1.35,
            'Page {PAGE_NUM} of {PAGE_COUNT}',
            $font,
            $size,
            [0.502, 0.525, 0.545],
        );
    }

    public function download(string $view, array $data, string $name, string $paper = 'a4'): Response
    {
        $filename = (Str::slug($name) ?: 'document').'.pdf';

        return response($this->render($view, $data, $paper), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** "₱1,234.56" from centavos. */
    public static function money(?int $cents): string
    {
        return '₱'.number_format(($cents ?? 0) / 100, 2);
    }
}
