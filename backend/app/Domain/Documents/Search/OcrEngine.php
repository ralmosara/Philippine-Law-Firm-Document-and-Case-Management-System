<?php

namespace App\Domain\Documents\Search;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Optical character recognition with Tesseract, for scanned pleadings,
 * photographed receipts and image-only PDFs. PDF pages are rasterised with
 * Poppler's pdftoppm first. Both run as separate processes with time limits.
 */
class OcrEngine
{
    /** Images Tesseract (via Leptonica) can read. */
    public const IMAGE_TYPES = ['jpg', 'jpeg', 'png', 'tif', 'tiff', 'webp', 'bmp', 'gif'];

    public function enabled(): bool
    {
        return (bool) config('services.ocr.enabled');
    }

    public function image(string $path): string
    {
        $result = Process::timeout((int) config('services.ocr.timeout', 300))
            ->run([config('services.ocr.tesseract', 'tesseract'), $path, 'stdout', '-l', config('services.ocr.languages', 'eng'), '--psm', '3']);

        if (! $result->successful()) {
            throw new RuntimeException('OCR failed: '.trim($result->errorOutput()));
        }

        return $result->output();
    }

    /** Rasterise up to `max_pages` pages at 300 dpi and read each one. */
    public function pdf(string $path): string
    {
        $dir = storage_path('app/ocr-'.bin2hex(random_bytes(8)));
        File::ensureDirectoryExists($dir);

        try {
            $result = Process::timeout((int) config('services.ocr.timeout', 300))->run([
                config('services.ocr.pdftoppm', 'pdftoppm'), '-r', '300', '-gray', '-png',
                '-f', '1', '-l', (string) config('services.ocr.max_pages', 30),
                $path, "{$dir}/page",
            ]);

            if (! $result->successful()) {
                throw new RuntimeException('Could not render the PDF for OCR: '.trim($result->errorOutput()));
            }

            $pages = File::glob("{$dir}/page*.png");
            natsort($pages);

            return implode("\n\f\n", array_map(fn (string $page) => $this->image($page), $pages));
        } finally {
            File::deleteDirectory($dir);
        }
    }
}
