<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Documents\Actions\StoreMatterFile;
use App\Domain\Documents\Models\MatterFile;
use App\Domain\Documents\Search\FileSearch;
use App\Domain\Matters\Models\Matter;
use App\Http\Controllers\Controller;
use App\Http\Resources\MatterFileResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MatterFileController extends Controller
{
    public function index(Matter $matter): AnonymousResourceCollection
    {
        return MatterFileResource::collection(
            $matter->files()->select(FileSearch::COLUMNS)->with('uploader')->latest()->get()
        );
    }

    public function store(Request $request, Matter $matter, StoreMatterFile $store): JsonResponse
    {
        Gate::authorize('work-matters');

        $validated = $request->validate([
            'file' => ['required', StoreMatterFile::rule()],
            'description' => ['nullable', 'string', 'max:500'],
            'shared_with_client' => ['boolean'],
        ]);

        $file = $store->execute($matter, $validated['file'], $request->user(), $validated['description'] ?? null, $validated['shared_with_client'] ?? false);

        return (new MatterFileResource($file->fresh(['uploader'])))->response()->setStatusCode(201);
    }

    /** Search file names, descriptions and contents across the firm (or one matter). */
    public function search(Request $request, FileSearch $search): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'search' => ['required', 'string', 'max:200'],
            'matter_id' => ['nullable', 'integer'],
        ]);

        $terms = $search->terms($validated['search']);
        $files = $search->query($validated['search'], $validated['matter_id'] ?? null)
            ->with(['uploader', 'matter:id,reference,title'])
            ->paginate($this->perPage($request, 25))
            ->through(fn (MatterFile $file) => $search->withSnippet($file, $terms));

        return MatterFileResource::collection($files);
    }

    public function update(Request $request, MatterFile $file): MatterFileResource
    {
        Gate::authorize('work-matters');

        $file->update($request->validate([
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'shared_with_client' => ['sometimes', 'boolean'],
        ]));

        return new MatterFileResource($file->load('uploader'));
    }

    public function destroy(MatterFile $file): JsonResponse
    {
        Gate::authorize('work-matters');

        $file->delete();

        return response()->json(null, 204);
    }

    public function download(MatterFile $file): StreamedResponse
    {
        return static::stream($file);
    }

    /** Always a download, never rendered inline, so an upload cannot run script in the app's origin. */
    public static function stream(MatterFile $file): StreamedResponse
    {
        $disk = Storage::disk(MatterFile::disk());

        abort_unless($disk->exists($file->path), 404);

        return $disk->download($file->path, $file->original_name, [
            'Content-Type' => $file->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
