<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Imports\DocumentImports;
use App\Domain\Imports\Models\DocumentImport;
use App\Domain\Matters\Models\Matter;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Firm Settings > Import data > Documents: a ZIP of the firm's old files, one folder per matter. */
class DocumentImportController extends Controller
{
    public function __construct(private readonly DocumentImports $imports) {}

    public function index(): JsonResponse
    {
        Gate::authorize('manage-firm');

        return response()->json(['data' => DocumentImport::query()->with('creator:id,name')->latest('id')->limit(20)->get()->map(fn ($i) => $this->summary($i))]);
    }

    public function show(DocumentImport $documentImport): JsonResponse
    {
        Gate::authorize('manage-firm');

        return response()->json($this->detail($documentImport));
    }

    public function start(Request $request): JsonResponse
    {
        Gate::authorize('manage-firm');
        $validated = $request->validate(['filename' => ['required', 'string', 'max:255'], 'size' => ['required', 'integer', 'min:1']]);
        $import = $this->imports->start($request->user()->firm_id, $request->user(), $validated['filename'], (int) $validated['size']);

        return response()->json([...$this->summary($import), 'chunk_bytes' => DocumentImports::CHUNK_BYTES], 201);
    }

    public function chunk(Request $request, DocumentImport $documentImport, int $index): JsonResponse
    {
        Gate::authorize('manage-firm');
        $request->validate(['chunk' => ['required', 'file', 'max:'.(DocumentImports::CHUNK_BYTES / 1024)]]);
        $import = $this->imports->appendChunk($documentImport, $index, $request->file('chunk'));

        return response()->json(['received_chunks' => $import->received_chunks, 'received_bytes' => $import->received_bytes]);
    }

    public function finish(DocumentImport $documentImport): JsonResponse
    {
        Gate::authorize('manage-firm');

        return response()->json($this->detail($this->imports->finish($documentImport)));
    }

    public function assign(Request $request, DocumentImport $documentImport): JsonResponse
    {
        Gate::authorize('manage-firm');
        $validated = $request->validate(['folder' => ['present', 'nullable', 'string', 'max:255'], 'matter_id' => ['nullable', 'integer']]);

        return response()->json($this->detail($this->imports->assign($documentImport, (string) ($validated['folder'] ?? ''), $validated['matter_id'] ?? null)));
    }

    public function commit(DocumentImport $documentImport): JsonResponse
    {
        Gate::authorize('manage-firm');

        return response()->json($this->detail($this->imports->commit($documentImport)));
    }

    public function undo(DocumentImport $documentImport): JsonResponse
    {
        Gate::authorize('manage-firm');
        $result = $this->imports->undo($documentImport);

        return response()->json([...$result, 'import' => $this->detail($documentImport->fresh())]);
    }

    private function summary(DocumentImport $i): array
    {
        return [
            'id' => $i->id,
            'filename' => $i->filename,
            'size_bytes' => $i->size_bytes,
            'received_bytes' => $i->received_bytes,
            'received_chunks' => $i->received_chunks,
            'status' => $i->status,
            'summary' => $i->summary,
            'error' => $i->error,
            'can_undo' => $i->canUndo(),
            'created_by' => $i->creator?->name,
            'created_at' => $i->created_at?->toIso8601String(),
            'finished_at' => $i->finished_at?->toIso8601String(),
        ];
    }

    private function detail(DocumentImport $i): array
    {
        $matters = Matter::query()->whereIn('id', collect($i->folders ?? [])->pluck('matter_id')->filter())->get(['id', 'reference', 'title'])->keyBy('id');

        return [
            ...$this->summary($i->loadMissing('creator:id,name')),
            'folders' => collect($i->folders ?? [])->map(fn ($f) => [
                'folder' => $f['folder'],
                'matter' => $f['matter_id'] && $matters->has($f['matter_id']) ? ['id' => $f['matter_id'], 'reference' => $matters[$f['matter_id']]->reference, 'title' => $matters[$f['matter_id']]->title] : null,
                'matched_by' => $f['matched_by'],
                'counts' => collect($f['files'])->countBy('status')->all(),
                'files' => collect($f['files'])->map(fn ($file) => collect($file)->only(['name', 'path', 'size', 'status', 'reason', 'file_id'])->all())->values(),
            ])->values(),
        ];
    }
}
