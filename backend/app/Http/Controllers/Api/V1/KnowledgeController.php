<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Documents\Models\Document;
use App\Domain\Knowledge\KnowledgeSearch;
use App\Domain\Knowledge\Models\KnowledgeItem;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** The firm's knowledge bank. */
class KnowledgeController extends Controller
{
    public function __construct(private readonly KnowledgeSearch $search) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:200'],
            'kind' => ['nullable', Rule::in(array_keys(KnowledgeItem::KINDS))],
            'tag' => ['nullable', 'string', 'max:50'],
            'practice_area' => ['nullable', 'string', 'max:100'],
        ]);
        $terms = $this->search->terms((string) ($validated['search'] ?? ''));

        $items = $this->search->query((string) ($validated['search'] ?? ''))
            ->with('creator:id,name')
            ->when($validated['kind'] ?? null, fn ($q, $kind) => $q->where('kind', $kind))
            ->when($validated['practice_area'] ?? null, fn ($q, $area) => $q->where('practice_area', $area))
            ->get()
            // Tags are a JSON list; filtered here so SQLite and PostgreSQL behave alike.
            ->when($validated['tag'] ?? null, fn ($c, $tag) => $c->filter(fn (KnowledgeItem $i) => in_array(mb_strtolower($tag), array_map('mb_strtolower', $i->tags ?? []), true)))
            ->take(200)
            ->values();

        return response()->json([
            'kinds' => KnowledgeItem::KINDS,
            'tags' => $this->popularTags(),
            'data' => $items->map(fn (KnowledgeItem $i) => [...$this->present($i, false), 'snippet' => $this->search->snippet($i, $terms)]),
        ]);
    }

    public function show(KnowledgeItem $knowledgeItem): JsonResponse
    {
        return response()->json($this->present($knowledgeItem->load(['creator:id,name', 'updater:id,name', 'sourceMatter:id,reference,title']), true));
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('work-matters');
        $item = KnowledgeItem::create([
            ...$this->validated($request),
            'firm_id' => $request->user()->firm_id,
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);

        return response()->json($this->present($item->load('creator:id,name'), true), 201);
    }

    public function update(Request $request, KnowledgeItem $knowledgeItem): JsonResponse
    {
        Gate::authorize('work-matters');
        $knowledgeItem->update([...$this->validated($request), 'updated_by' => $request->user()->id]);

        return response()->json($this->present($knowledgeItem->load(['creator:id,name', 'updater:id,name']), true));
    }

    public function destroy(Request $request, KnowledgeItem $knowledgeItem): JsonResponse
    {
        abort_unless($knowledgeItem->created_by === $request->user()->id || $request->user()->role->canManageFirm(), 403, 'Only whoever added it, or a managing partner, can remove it.');
        $knowledgeItem->delete();

        return response()->json(null, 204);
    }

    /** Keep a document that worked (its latest version) as a model for next time. */
    public function fromDocument(Request $request, Document $document): JsonResponse
    {
        Gate::authorize('work-matters');
        $validated = $request->validate([
            'kind' => ['nullable', Rule::in(array_keys(KnowledgeItem::KINDS))],
            'title' => ['nullable', 'string', 'max:300'],
            'tags' => ['nullable', 'array', 'max:20'],
            'tags.*' => ['string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
        $document->loadMissing(['latestVersion', 'matter:id,case_type']);

        $item = KnowledgeItem::create([
            'firm_id' => $document->firm_id,
            'kind' => $validated['kind'] ?? 'pleading',
            'title' => $validated['title'] ?? $document->title,
            'doctrine' => $validated['notes'] ?? null,
            'body' => (string) $document->latestVersion?->content,
            'practice_area' => $document->matter?->case_type,
            'tags' => $this->cleanTags($validated['tags'] ?? []),
            'source_document_id' => $document->id,
            'source_matter_id' => $document->matter_id,
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);

        return response()->json($this->present($item, true), 201);
    }

    private function validated(Request $request): array
    {
        $validated = $request->validate([
            'kind' => ['required', Rule::in(array_keys(KnowledgeItem::KINDS))],
            'title' => ['required', 'string', 'max:300'],
            'citation' => ['nullable', 'string', 'max:300'],
            'doctrine' => ['nullable', 'string', 'max:10000'],
            'body' => ['nullable', 'string', 'max:500000'],
            'practice_area' => ['nullable', 'string', 'max:100'],
            'tags' => ['nullable', 'array', 'max:20'],
            'tags.*' => ['string', 'max:50'],
        ]);

        return [...$validated, 'tags' => $this->cleanTags($validated['tags'] ?? [])];
    }

    /** @return list<string> */
    private function cleanTags(array $tags): array
    {
        return array_values(array_unique(array_filter(array_map(fn ($t) => mb_strtolower(trim((string) $t)), $tags))));
    }

    /** @return list<string> the most used tags, for filters and suggestions */
    private function popularTags(): array
    {
        return KnowledgeItem::query()->whereNotNull('tags')->latest('id')->limit(1000)->pluck('tags')
            ->flatten()->countBy()->sortDesc()->keys()->take(40)->values()->all();
    }

    private function present(KnowledgeItem $i, bool $full): array
    {
        return [
            'id' => $i->id,
            'kind' => $i->kind,
            'kind_label' => KnowledgeItem::KINDS[$i->kind] ?? $i->kind,
            'title' => $i->title,
            'citation' => $i->citation,
            'doctrine' => $i->doctrine,
            'practice_area' => $i->practice_area,
            'tags' => $i->tags ?? [],
            'created_by' => $i->relationLoaded('creator') ? $i->creator?->name : null,
            'created_by_id' => $i->created_by,
            'updated_at' => $i->updated_at?->toIso8601String(),
            ...($full ? [
                'body' => $i->body,
                'updated_by' => $i->relationLoaded('updater') ? $i->updater?->name : null,
                'source_document_id' => $i->source_document_id,
                'source_matter' => $i->relationLoaded('sourceMatter') && $i->sourceMatter ? ['id' => $i->sourceMatter->id, 'reference' => $i->sourceMatter->reference, 'title' => $i->sourceMatter->title] : null,
            ] : []),
        ];
    }
}
