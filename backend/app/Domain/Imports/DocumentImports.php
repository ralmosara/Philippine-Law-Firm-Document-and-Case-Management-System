<?php

namespace App\Domain\Imports;

use App\Domain\Documents\Actions\StoreMatterFile;
use App\Domain\Documents\Models\MatterFile;
use App\Domain\Imports\Jobs\ProcessDocumentImport;
use App\Domain\Imports\Models\DocumentImport;
use App\Domain\Matters\Models\Matter;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;
use ZipArchive;

/**
 * Bulk import of a firm's existing documents from a ZIP: one top-level
 * folder per matter, named by its reference or case number (or matched by
 * hand in the preview); subfolders are kept as each file's description.
 *
 * The ZIP arrives in pieces (so it is not limited by the upload size, and
 * a dropped connection does not lose it), is checked without unpacking
 * (allowed types, 20 MB per file, a total unpacked size, no paths taken
 * from the archive), and its files are filed in the background exactly as
 * an upload is: virus-scanned, type-checked, made searchable.
 */
class DocumentImports
{
    public const CHUNK_BYTES = 10 * 1024 * 1024;

    /** Folder and file names that are not documents. */
    private const SKIP = ['__MACOSX', '.DS_Store', 'Thumbs.db', 'desktop.ini'];

    public function start(int $firmId, User $by, string $filename, int $size): DocumentImport
    {
        if (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== 'zip') {
            throw ValidationException::withMessages(['filename' => 'Upload a .zip file.']);
        }
        $max = (int) config('services.document_imports.max_mb', 2048) * 1024 * 1024;
        if ($size < 22 || $size > $max) {
            throw ValidationException::withMessages(['size' => 'The ZIP must be at most '.round($max / 1024 / 1024 / 1024, 1).' GB; split larger archives into several.']);
        }

        return DocumentImport::create(['firm_id' => $firmId, 'filename' => mb_substr($filename, 0, 255), 'size_bytes' => $size, 'created_by' => $by->id]);
    }

    /** Pieces arrive in order; a repeated piece (a retry after a dropped connection) is accepted once. */
    public function appendChunk(DocumentImport $import, int $index, UploadedFile $chunk): DocumentImport
    {
        return DB::transaction(function () use ($import, $index, $chunk) {
            $import = DocumentImport::whereKey($import->id)->lockForUpdate()->firstOrFail();
            if ($import->status !== DocumentImport::UPLOADING) {
                throw ValidationException::withMessages(['chunk' => 'This upload is already complete.']);
            }
            if ($index < $import->received_chunks) {
                return $import; // already have it
            }
            if ($index !== $import->received_chunks) {
                throw ValidationException::withMessages(['chunk' => "Expected piece {$import->received_chunks}, got {$index}."]);
            }
            $bytes = (int) $chunk->getSize();
            if ($import->received_bytes + $bytes > $import->size_bytes || $bytes > self::CHUNK_BYTES) {
                throw ValidationException::withMessages(['chunk' => 'This piece does not fit the declared size.']);
            }

            $disk = Storage::disk('local');
            $disk->makeDirectory(dirname($import->archivePath()));
            $out = fopen($disk->path($import->archivePath()), $index === 0 ? 'wb' : 'ab');
            $in = fopen((string) $chunk->getRealPath(), 'rb');
            stream_copy_to_stream($in, $out);
            fclose($in);
            fclose($out);

            $import->forceFill(['received_chunks' => $index + 1, 'received_bytes' => $import->received_bytes + $bytes])->save();

            return $import;
        });
    }

    /** All pieces in: read the ZIP's table of contents and match folders to matters. */
    public function finish(DocumentImport $import): DocumentImport
    {
        if ($import->status !== DocumentImport::UPLOADING || $import->received_bytes !== $import->size_bytes) {
            throw ValidationException::withMessages(['chunk' => 'The upload is not complete yet.']);
        }

        $zip = new ZipArchive;
        if ($zip->open(Storage::disk('local')->path($import->archivePath()), ZipArchive::RDONLY) !== true) {
            $this->fail($import, 'This is not a ZIP file we can read.');
            throw ValidationException::withMessages(['file' => 'This is not a ZIP file we can read.']);
        }

        $maxFiles = (int) config('services.document_imports.max_files', 5000);
        $maxTotal = (int) config('services.document_imports.max_unpacked_mb', 10240) * 1024 * 1024;
        $perFile = MatterFile::MAX_KILOBYTES * 1024;
        $folders = [];
        $total = 0;
        $count = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $name = str_replace('\\', '/', (string) $stat['name']);
            if (str_ends_with($name, '/')) {
                continue; // a folder entry
            }
            $parts = array_values(array_filter(explode('/', $name), fn ($p) => $p !== '' && $p !== '.'));
            $base = end($parts) ?: '';
            if ($parts === [] || array_intersect($parts, self::SKIP) !== [] || str_starts_with($base, '.') || str_starts_with($base, '~$')) {
                continue;
            }
            if (++$count > $maxFiles) {
                $this->fail($import, "The ZIP has more than {$maxFiles} files; split it into several.");
                throw ValidationException::withMessages(['file' => "The ZIP has more than {$maxFiles} files; split it into several."]);
            }
            $total += (int) $stat['size'];
            if ($total > $maxTotal) {
                $this->fail($import, 'The ZIP unpacks to more than allowed; split it into several.');
                throw ValidationException::withMessages(['file' => 'The ZIP unpacks to more than allowed; split it into several.']);
            }

            $folder = count($parts) > 1 ? $parts[0] : '';
            $sub = count($parts) > 2 ? implode(' / ', array_slice($parts, 1, -1)) : null;
            $extension = strtolower(pathinfo($base, PATHINFO_EXTENSION));
            $reason = match (true) {
                ! in_array($extension, MatterFile::ALLOWED_TYPES, true) => 'This type of file is not accepted.',
                (int) $stat['size'] > $perFile => 'Larger than 20 MB.',
                (int) $stat['size'] === 0 => 'The file is empty.',
                default => null,
            };

            $folders[$folder] ??= ['folder' => $folder, 'matter_id' => null, 'matched_by' => null, 'files' => []];
            $folders[$folder]['files'][] = [
                'entry' => $i,
                'name' => mb_substr($base, 0, 255),
                'path' => $sub,
                'size' => (int) $stat['size'],
                'status' => $reason ? 'skipped' : 'ready',
                'reason' => $reason,
                'file_id' => null,
            ];
        }
        $zip->close();

        if ($folders === []) {
            $this->fail($import, 'The ZIP has no documents in it.');
            throw ValidationException::withMessages(['file' => 'The ZIP has no documents in it.']);
        }

        $matcher = new MatterMatcher;
        foreach ($folders as &$f) {
            if ($f['folder'] !== '' && ($matter = $matcher->match($f['folder']))) {
                $f['matter_id'] = $matter->id;
                $f['matched_by'] = 'name';
            }
        }
        unset($f);

        $import->forceFill(['status' => DocumentImport::PREVIEWED, 'folders' => array_values($folders)])->save();

        return $import;
    }

    /** In the preview: file a folder under a matter, or (null) leave it out. */
    public function assign(DocumentImport $import, string $folder, ?int $matterId): DocumentImport
    {
        if ($import->status !== DocumentImport::PREVIEWED) {
            throw ValidationException::withMessages(['folder' => 'Folders can only be matched before importing.']);
        }
        if ($matterId !== null && ! Matter::query()->whereKey($matterId)->exists()) {
            throw ValidationException::withMessages(['matter_id' => 'Choose one of the firm\'s matters.']);
        }

        $folders = collect($import->folders)->map(fn ($f) => $f['folder'] === $folder ? [...$f, 'matter_id' => $matterId, 'matched_by' => $matterId ? 'hand' : null] : $f)->all();
        $import->forceFill(['folders' => $folders])->save();

        return $import;
    }

    public function commit(DocumentImport $import): DocumentImport
    {
        if ($import->status !== DocumentImport::PREVIEWED) {
            throw ValidationException::withMessages(['import' => 'This import has already started.']);
        }
        if (! collect($import->folders)->contains(fn ($f) => $f['matter_id'] && collect($f['files'])->contains('status', 'ready'))) {
            throw ValidationException::withMessages(['import' => 'No folder is matched to a matter yet.']);
        }

        $import->forceFill(['status' => DocumentImport::IMPORTING, 'committed_at' => now()])->save();
        ProcessDocumentImport::dispatch($import->firm_id, $import->id);

        return $import;
    }

    /**
     * File the next batch. Returns true when files remain (the job queues
     * itself again), so a large archive never runs into the job time limit.
     */
    public function processBatch(DocumentImport $import, int $batch = 40): bool
    {
        $by = User::findOrFail($import->created_by);
        $folders = $import->folders;
        $zip = new ZipArchive;
        if ($zip->open(Storage::disk('local')->path($import->archivePath()), ZipArchive::RDONLY) !== true) {
            $this->fail($import, 'The uploaded ZIP is no longer readable; upload it again.');

            return false;
        }

        $done = 0;
        try {
            foreach ($folders as $fi => $folder) {
                $matter = $folder['matter_id'] ? Matter::query()->find($folder['matter_id']) : null;
                foreach ($folder['files'] as $i => $file) {
                    if ($file['status'] !== 'ready') {
                        continue;
                    }
                    if ($matter === null) {
                        $folders[$fi]['files'][$i] = [...$file, 'status' => 'skipped', 'reason' => 'The folder was not matched to a matter.'];

                        continue;
                    }
                    if ($done >= $batch) {
                        break 2;
                    }
                    $folders[$fi]['files'][$i] = $this->fileOne($zip, $file, $matter, $by, $folder['folder']);
                    $done++;
                }
            }
        } finally {
            $zip->close();
        }

        $remaining = collect($folders)->flatMap(fn ($f) => $f['files'])->contains('status', 'ready');
        $import->forceFill(['folders' => $folders, 'summary' => $this->summarize($folders)])->save();

        if (! $remaining) {
            $import->forceFill(['status' => DocumentImport::DONE, 'finished_at' => now()])->save();
            Storage::disk('local')->delete($import->archivePath());
        }

        return $remaining;
    }

    /** Take the import back: the files it filed, unless they have been used since. */
    public function undo(DocumentImport $import): array
    {
        if (! $import->canUndo()) {
            throw ValidationException::withMessages(['import' => 'This import can no longer be undone.']);
        }

        $kept = [];
        $undone = 0;
        $folders = $import->folders;
        foreach ($folders as $fi => $folder) {
            foreach ($folder['files'] as $i => $file) {
                if ($file['status'] !== 'imported' || ! $file['file_id']) {
                    continue;
                }
                $model = MatterFile::query()->find($file['file_id']);
                if ($model === null) {
                    continue;
                }
                if ($used = $this->usedSince($model)) {
                    $kept[] = "{$file['name']}: {$used}";

                    continue;
                }
                $model->delete();
                $folders[$fi]['files'][$i]['status'] = 'undone';
                $undone++;
            }
        }
        $import->forceFill(['status' => DocumentImport::UNDONE, 'undone_at' => now(), 'folders' => $folders, 'summary' => [...($import->summary ?? []), 'undone' => $undone]])->save();

        return ['undone' => $undone, 'kept' => $kept];
    }

    private function fileOne(ZipArchive $zip, array $file, Matter $matter, User $by, string $folder): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'docimp');
        try {
            $in = $zip->getStream($zip->getNameIndex($file['entry']));
            if ($in === false) {
                return [...$file, 'status' => 'failed', 'reason' => 'Could not be read from the ZIP.'];
            }
            $out = fopen($tmp, 'wb');
            // Never more than the size the ZIP declared (a bomb would claim small and unpack large).
            $copied = stream_copy_to_stream($in, $out, $file['size'] + 1);
            fclose($in);
            fclose($out);
            if ($copied !== $file['size']) {
                return [...$file, 'status' => 'failed', 'reason' => 'The file in the ZIP is damaged.'];
            }

            $sha = hash_file('sha256', $tmp);
            if (MatterFile::query()->where('matter_id', $matter->id)->where('sha256', $sha)->exists()) {
                return [...$file, 'status' => 'skipped', 'reason' => 'Already filed in this matter.'];
            }

            $description = 'Imported'.($file['path'] ? ": {$file['path']}" : ($folder !== '' ? ": {$folder}" : ''));
            $stored = app(StoreMatterFile::class)->execute($matter, new UploadedFile($tmp, $file['name'], null, null, true), $by, mb_substr($description, 0, 500));

            return [...$file, 'status' => 'imported', 'reason' => null, 'file_id' => $stored->id];
        } catch (ValidationException $e) {
            return [...$file, 'status' => 'failed', 'reason' => collect($e->errors())->flatten()->first() ?? 'Refused.'];
        } catch (HttpExceptionInterface $e) {
            return [...$file, 'status' => 'failed', 'reason' => $e->getMessage() ?: 'Refused.'];
        } catch (Throwable $e) {
            report($e);

            return [...$file, 'status' => 'failed', 'reason' => 'Could not be filed.'];
        } finally {
            @unlink($tmp);
        }
    }

    private function usedSince(MatterFile $file): ?string
    {
        foreach (['exhibits' => 'marked as an exhibit', 'e_filings' => 'part of an e-filing', 'document_request_items' => 'attached to a document request', 'messages' => 'sent in a message'] as $table => $what) {
            $column = $table === 'e_filings' ? 'package_file_id' : 'matter_file_id';
            if (DB::table($table)->where($column, $file->id)->exists()) {
                return "kept, {$what} since the import";
            }
        }
        if ($file->shared_with_client) {
            return 'kept, shared with the client since the import';
        }

        return null;
    }

    private function summarize(array $folders): array
    {
        $files = collect($folders)->flatMap(fn ($f) => $f['files']);

        return [
            'imported' => $files->where('status', 'imported')->count(),
            'skipped' => $files->where('status', 'skipped')->count(),
            'failed' => $files->where('status', 'failed')->count(),
            'remaining' => $files->where('status', 'ready')->count(),
        ];
    }

    private function fail(DocumentImport $import, string $error): void
    {
        $import->forceFill(['status' => DocumentImport::FAILED, 'error' => $error])->save();
        Storage::disk('local')->delete($import->archivePath());
    }
}
