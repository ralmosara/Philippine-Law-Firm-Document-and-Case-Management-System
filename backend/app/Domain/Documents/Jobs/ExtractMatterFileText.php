<?php

namespace App\Domain\Documents\Jobs;

use App\Domain\Documents\Models\MatterFile;
use App\Domain\Documents\Search\FileTextExtractor;
use App\Domain\Documents\Search\OcrEngine;
use App\Jobs\Concerns\RunsOnHeavyQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Makes an uploaded file searchable by extracting its text in the
 * background, so large PDFs never slow down the upload itself.
 */
class ExtractMatterFileText implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RunsOnHeavyQueue;

    public int $tries = 2;

    /** OCR of a long scanned PDF takes minutes; keep the queue's retry_after above this. */
    public int $timeout = 600;

    /** A PDF with fewer readable characters than this is treated as scanned images. */
    private const SCANNED_PDF_THRESHOLD = 50;

    public function __construct(public readonly int $fileId)
    {
        $this->useHeavyQueue();
    }

    public function handle(FileTextExtractor $extractor, OcrEngine $ocr): void
    {
        $file = MatterFile::withoutGlobalScopes()->withTrashed()->find($this->fileId);

        if ($file === null) {
            return;
        }

        $extension = strtolower(pathinfo($file->original_name, PATHINFO_EXTENSION));
        $isImage = in_array($extension, OcrEngine::IMAGE_TYPES, true);

        if (! $extractor->supports($extension) && ! ($isImage && $ocr->enabled())) {
            $file->forceFill(['text_status' => 'unsupported'])->saveQuietly();

            return;
        }

        $local = $this->localCopy($file);

        try {
            try {
                $text = $isImage ? '' : (string) $extractor->extract($local, $extension);
            } catch (Throwable $e) {
                // Some scanner-produced PDFs have no parsable text layer; OCR can still read them.
                if ($extension !== 'pdf' || ! $ocr->enabled()) {
                    throw $e;
                }
                $text = '';
            }
            $source = 'text';

            $scannedPdf = $extension === 'pdf' && mb_strlen((string) preg_replace('/\s+/u', '', $text)) < self::SCANNED_PDF_THRESHOLD;
            if ($ocr->enabled() && ($isImage || $scannedPdf)) {
                $text = $extractor->normalize($isImage ? $ocr->image($local) : $ocr->pdf($local));
                $source = 'ocr';
            }

            $file->forceFill([
                'content_text' => $text !== '' ? $text : null,
                'text_status' => $text !== '' ? 'extracted' : 'unsupported',
                'text_source' => $text !== '' ? $source : null,
            ])->saveQuietly();
        } catch (Throwable $e) {
            // A damaged or encrypted file is still stored; it is just not searchable.
            Log::warning('Text extraction failed', ['matter_file_id' => $file->id, 'error' => $e->getMessage()]);
            $file->forceFill(['text_status' => 'failed'])->saveQuietly();
        } finally {
            if ($local !== $this->diskPath($file)) {
                @unlink($local);
            }
        }
    }

    /** The disk's own path when it is local, otherwise a temporary download. */
    private function localCopy(MatterFile $file): string
    {
        if ($path = $this->diskPath($file)) {
            return $path;
        }

        $temp = tempnam(sys_get_temp_dir(), 'mf');
        $source = Storage::disk(MatterFile::disk())->readStream($file->path);
        $target = fopen($temp, 'wb');
        stream_copy_to_stream($source, $target);
        fclose($source);
        fclose($target);

        return $temp;
    }

    private function diskPath(MatterFile $file): ?string
    {
        return config('filesystems.disks.'.MatterFile::disk().'.driver') === 'local'
            ? Storage::disk(MatterFile::disk())->path($file->path)
            : null;
    }
}
