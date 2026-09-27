<?php

namespace App\Domain\Documents\Actions;

use App\Domain\Documents\Jobs\ExtractMatterFileText;
use App\Domain\Documents\Models\MatterFile;
use App\Domain\Documents\Scanning\ScannerUnavailable;
use App\Domain\Documents\Scanning\ScanResult;
use App\Domain\Documents\Scanning\VirusScanner;
use App\Domain\Matters\Models\Client;
use App\Domain\Matters\Models\Matter;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\ValidationException;

/**
 * Stores an uploaded file on a matter, whoever sends it (staff, or a client
 * attaching it to a portal message): virus-scanned first, saved privately
 * under an unguessable name, fingerprinted, then indexed for search.
 */
class StoreMatterFile
{
    public function __construct(private readonly VirusScanner $scanner) {}

    /** The validation rule for an upload, shared by every entry point. */
    public static function rule(): File
    {
        return File::types(MatterFile::ALLOWED_TYPES)->max(MatterFile::MAX_KILOBYTES);
    }

    public function execute(Matter $matter, UploadedFile $upload, User|Client $by, ?string $description = null, bool $sharedWithClient = false): MatterFile
    {
        $scan = $this->scan($upload, $matter, $by);

        $extension = strtolower($upload->guessExtension() ?: $upload->getClientOriginalExtension());
        $path = $upload->storeAs(
            "firms/{$matter->firm_id}/matters/{$matter->id}",
            Str::uuid()->toString().($extension !== '' ? ".{$extension}" : ''),
            MatterFile::disk(),
        );

        if ($path === false) {
            abort(500, 'The file could not be stored. Please try again.');
        }

        $file = $matter->files()->create([
            'firm_id' => $matter->firm_id,
            'uploaded_by' => $by instanceof User ? $by->id : null,
            'original_name' => Str::limit($this->safeName($upload->getClientOriginalName()), 250, ''),
            'path' => $path,
            'mime_type' => $upload->getMimeType() ?: 'application/octet-stream',
            'size_bytes' => $upload->getSize(),
            'sha256' => hash_file('sha256', $upload->getRealPath()),
            'description' => $description,
            'shared_with_client' => $sharedWithClient,
            'scan_status' => $scan->status,
            'scanned_at' => $scan->status === ScanResult::CLEAN ? now() : null,
        ]);

        if ($by instanceof Client) {
            $file->forceFill(['uploaded_by_client_id' => $by->id])->saveQuietly();
        }

        ExtractMatterFileText::dispatch($file->id);

        return $file;
    }

    /**
     * Scan before anything is written to storage. Malware is refused and
     * logged. If the scanner is down, uploads stop (fail closed) unless
     * CLAMAV_FAIL_OPEN allows storing the file as "not scanned".
     */
    private function scan(UploadedFile $upload, Matter $matter, User|Client $by): ScanResult
    {
        try {
            $result = $this->scanner->scan($upload->getRealPath());
        } catch (ScannerUnavailable $e) {
            report($e);

            if (! config('services.clamav.fail_open')) {
                abort(503, 'Files cannot be uploaded right now because the virus scanner is unavailable. Please try again in a few minutes.');
            }

            return ScanResult::notScanned();
        }

        if ($result->isInfected()) {
            AuditLog::record('file_rejected_malware', $matter->firm_id, $by, $matter, [
                'name' => $upload->getClientOriginalName(),
                'signature' => $result->signature,
                'sha256' => hash_file('sha256', $upload->getRealPath()),
            ]);

            throw ValidationException::withMessages([
                'file' => "This file contains malware ({$result->signature}) and was not saved.",
            ]);
        }

        return $result;
    }

    private function safeName(string $name): string
    {
        $name = preg_replace('/[\x00-\x1F\x7F\/\\\\]+/u', '_', $name) ?? 'file';

        return trim($name) !== '' ? $name : 'file';
    }
}
