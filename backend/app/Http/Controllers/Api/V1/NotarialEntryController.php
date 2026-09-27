<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Documents\Models\NotarialEntry;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * The notarial register. Each notary keeps their own register; entries are
 * numbered sequentially per book and series year.
 */
class NotarialEntryController extends Controller
{
    /** Entries per page of the physical register book. */
    public const ENTRIES_PER_PAGE = 10;

    public function index(Request $request): JsonResponse
    {
        $entries = NotarialEntry::query()
            ->with(['notary:id,name', 'matter:id,reference,title'])
            ->when($request->query('notary_id'), fn ($q, $id) => $q->where('notary_id', $id))
            ->when($request->query('series_year'), fn ($q, $year) => $q->where('series_year', $year))
            ->when($request->query('search'), fn ($q, $search) => $q->where(fn ($q) => $q
                ->whereLike('document_title', "%{$search}%")
                ->orWhereLike('principal_name', "%{$search}%")))
            ->orderByDesc('notarized_at')
            ->orderByDesc('id')
            ->paginate($this->perPage($request, 25));

        return JsonResource::collection($entries)->response();
    }

    /** The next Doc/Page/Book numbers for the current notary's register. */
    public function next(Request $request): JsonResponse
    {
        return response()->json($this->nextNumbers($request->user()->id, $request->integer('series_year', now()->year), $request->integer('book_number', 1)));
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('practice-law');

        $validated = $request->validate([
            'act_type' => ['required', Rule::in(NotarialEntry::ACT_TYPES)],
            'document_title' => ['required', 'string', 'max:255'],
            'principal_name' => ['required', 'string', 'max:255'],
            'competent_evidence' => ['required', 'string', 'max:255'],
            'fee_cents' => ['nullable', 'integer', 'min:0'],
            'notarized_at' => ['required', 'date', 'before_or_equal:now'],
            'book_number' => ['nullable', 'integer', 'min:1'],
            'matter_id' => ['nullable', 'integer', Rule::exists('matters', 'id')->where('firm_id', $request->user()->firm_id)],
            'document_id' => ['nullable', 'integer', Rule::exists('documents', 'id')->where('firm_id', $request->user()->firm_id)],
        ]);

        $notaryId = $request->user()->id;
        $year = (int) date('Y', strtotime($validated['notarized_at']));

        // Serialise numbering per notary so two entries never share a number.
        $entry = DB::transaction(function () use ($validated, $notaryId, $year) {
            NotarialEntry::where('notary_id', $notaryId)->where('series_year', $year)->lockForUpdate()->get(['id']);
            $numbers = $this->nextNumbers($notaryId, $year, $validated['book_number'] ?? null);

            return NotarialEntry::create([
                ...$validated,
                ...$numbers,
                'notary_id' => $notaryId,
                'fee_cents' => $validated['fee_cents'] ?? 0,
            ]);
        });

        return response()->json($entry->load(['notary:id,name', 'matter:id,reference,title']), 201);
    }

    /**
     * @return array{doc_number: int, page_number: int, book_number: int, series_year: int}
     */
    private function nextNumbers(int $notaryId, int $year, ?int $book = null): array
    {
        $book ??= (int) (NotarialEntry::where('notary_id', $notaryId)->where('series_year', $year)->max('book_number') ?: 1);

        $lastDoc = (int) NotarialEntry::where('notary_id', $notaryId)
            ->where('series_year', $year)
            ->where('book_number', $book)
            ->max('doc_number');

        $doc = $lastDoc + 1;

        return [
            'doc_number' => $doc,
            'page_number' => intdiv($doc - 1, self::ENTRIES_PER_PAGE) + 1,
            'book_number' => $book,
            'series_year' => $year,
        ];
    }
}
