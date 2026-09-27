<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Documents\Actions\CreateDocumentVersion;
use App\Domain\Documents\Enums\DocumentStatus;
use App\Domain\Documents\Enums\SignatureStatus;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\DocumentTemplate;
use App\Domain\Documents\Services\DocumentMerger;
use App\Domain\Matters\Models\Firm;
use App\Domain\Matters\Models\Matter;
use App\Http\Controllers\Controller;
use App\Http\Resources\DocumentResource;
use App\Http\Resources\DocumentVersionResource;
use App\Support\Pdf\PdfRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class DocumentController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $documents = Document::query()
            ->with(['matter', 'creator', 'template'])
            ->when($request->query('matter_id'), fn ($q, $id) => $q->where('matter_id', $id))
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->query('search'), fn ($q, $search) => $q->whereLike('title', "%{$search}%"))
            ->latest('updated_at')
            ->paginate($this->perPage($request, 25));

        return DocumentResource::collection($documents);
    }

    /**
     * Create a document on a matter, either generated from a template or
     * from supplied content.
     */
    public function store(Request $request, DocumentMerger $merger, CreateDocumentVersion $createVersion): JsonResponse
    {
        Gate::authorize('work-matters');

        $validated = $request->validate([
            'matter_id' => ['required', 'integer'],
            'template_id' => ['nullable', 'integer'],
            'title' => [Rule::requiredIf(! $request->filled('template_id')), 'nullable', 'string', 'max:255'],
            'content' => [Rule::requiredIf(! $request->filled('template_id')), 'nullable', 'string', 'max:500000'],
            'fields' => ['array'],
            'fields.*' => ['nullable', 'string', 'max:2000'],
        ]);

        $matter = Matter::findOrFail($validated['matter_id']);

        if (isset($validated['template_id'])) {
            $template = DocumentTemplate::findOrFail($validated['template_id']);
            $document = $merger->generate($template, $matter, $request->user(), $validated['fields'] ?? [], $validated['title'] ?? null);
        } else {
            $document = DB::transaction(function () use ($matter, $validated, $request, $createVersion) {
                $document = $matter->documents()->create([
                    'firm_id' => $matter->firm_id,
                    'title' => $validated['title'],
                    'created_by' => $request->user()->id,
                ]);
                $createVersion->execute($document, $validated['content'], $request->user(), 'Initial draft');

                return $document;
            });
        }

        return (new DocumentResource($document->load(['matter', 'creator', 'template', 'latestVersion.creator'])))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Document $document): DocumentResource
    {
        return new DocumentResource($document->load(['matter', 'creator', 'template', 'latestVersion.creator']));
    }

    public function update(Request $request, Document $document): DocumentResource
    {
        Gate::authorize('work-matters');

        $document->update($request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'shared_with_client' => ['sometimes', 'boolean'],
        ]));

        return new DocumentResource($document->load(['matter', 'creator', 'template', 'latestVersion.creator']));
    }

    public function destroy(Document $document): JsonResponse
    {
        Gate::authorize('work-matters');

        if (! $document->status->isEditable()) {
            abort(422, 'Only draft documents can be deleted.');
        }

        $document->delete();

        return response()->json(null, 204);
    }

    /** The latest version as a PDF on the firm's letterhead, with any e-signature records appended. */
    public function pdf(Document $document, PdfRenderer $pdf): Response
    {
        return $pdf->download('pdf.document', static::pdfData($document), $document->title);
    }

    public static function pdfData(Document $document): array
    {
        $document->loadMissing(['matter', 'latestVersion']);

        return [
            'document' => $document,
            'firm' => Firm::findOrFail($document->firm_id),
            'signatures' => $document->signatureRequests()
                ->where('status', SignatureStatus::Signed->value)
                ->with(['client', 'requester', 'version'])
                ->get()
                ->each(fn ($s) => $s->makeVisible('signature_image')),
        ];
    }

    public function versions(Document $document): AnonymousResourceCollection
    {
        return DocumentVersionResource::collection($document->versions()->with('creator')->get());
    }

    public function saveVersion(Request $request, Document $document, CreateDocumentVersion $createVersion): JsonResponse
    {
        Gate::authorize('work-matters');

        $validated = $request->validate([
            'content' => ['required', 'string', 'max:500000'],
            'change_summary' => ['nullable', 'string', 'max:255'],
        ]);

        $version = $createVersion->execute($document, $validated['content'], $request->user(), $validated['change_summary'] ?? null);

        return (new DocumentVersionResource($version->load('creator')))->response()->setStatusCode(201);
    }

    /**
     * Move a document along draft -> final -> signed -> notarized. Only
     * lawyers can finalise; a final document's content is frozen.
     */
    public function transition(Request $request, Document $document): DocumentResource
    {
        Gate::authorize('practice-law');

        $validated = $request->validate([
            'status' => ['required', Rule::in([DocumentStatus::Final->value, DocumentStatus::Signed->value, DocumentStatus::Notarized->value, DocumentStatus::Draft->value])],
        ]);

        $to = DocumentStatus::from($validated['status']);
        $allowed = match ($document->status) {
            DocumentStatus::Draft => [DocumentStatus::Final],
            DocumentStatus::Final => [DocumentStatus::Draft, DocumentStatus::Signed, DocumentStatus::Notarized],
            // Answered by the client in the portal, or cancelled; see ElectronicSignatures.
            DocumentStatus::PendingSignature => [],
            DocumentStatus::Signed => [DocumentStatus::Notarized],
            DocumentStatus::Notarized => [],
        };

        if (! in_array($to, $allowed, true)) {
            abort(422, $document->status === DocumentStatus::PendingSignature
                ? 'This document is waiting for the client\'s e-signature. Cancel the request to change its status.'
                : "A {$document->status->value} document cannot be marked {$to->value}.");
        }

        if ($document->current_version === 0) {
            abort(422, 'The document has no content yet.');
        }

        $document->forceFill(['status' => $to])->save();

        return new DocumentResource($document->load(['matter', 'creator', 'template', 'latestVersion.creator']));
    }
}
