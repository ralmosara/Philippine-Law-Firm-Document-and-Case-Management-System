<?php

namespace App\Support\Pdf;

use Barryvdh\DomPDF\Facade\Pdf;
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
    ];

    public function render(string $view, array $data, string $paper = 'a4'): string
    {
        return Pdf::setOption([
            'isRemoteEnabled' => false,
            'isPhpEnabled' => false,
            'isJavascriptEnabled' => false,
            'defaultFont' => 'DejaVu Sans',
            'dpi' => 96,
        ])
            ->loadView($view, $data)
            ->setPaper(self::PAPERS[$paper] ?? 'a4')
            ->output();
    }

    public function download(string $view, array $data, string $name): Response
    {
        $filename = (Str::slug($name) ?: 'document').'.pdf';

        return response($this->render($view, $data), 200, [
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
