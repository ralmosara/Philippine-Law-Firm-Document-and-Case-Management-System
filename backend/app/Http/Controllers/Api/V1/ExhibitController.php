<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Documents\Actions\CreateDocumentVersion;
use App\Domain\Evidence\ExhibitMarkings;
use App\Domain\Evidence\FormalOffer;
use App\Domain\Evidence\Models\Exhibit;
use App\Domain\Matters\Models\Matter;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** The evidence in a case: marking exhibits, tracking objections and rulings, the formal offer. */
class ExhibitController extends Controller
{
    public function __construct(private readonly ExhibitMarkings $markings, private readonly FormalOffer $offer) {}

    public function index(Matter $matter): JsonResponse
    {
        $exhibits = $this->markings->sort(Exhibit::where('matter_id', $matter->id)->with('file:id,original_name')->get());

        return response()->json([
            'exhibits' => $exhibits->map(fn (Exhibit $e) => $this->present($e)),
            'letters' => [
                'ours' => $this->markings->usesLetters($matter, Exhibit::OURS),
                'adverse' => $this->markings->usesLetters($matter, Exhibit::ADVERSE),
            ],
            'next' => [
                'ours' => $this->markings->next($matter, Exhibit::OURS),
                'adverse' => $this->markings->next($matter, Exhibit::ADVERSE),
            ],
            'client_role' => $matter->client_role,
        ]);
    }

    /** The next free marking, or sub-marking under a parent exhibit. */
    public function nextMarking(Request $request, Matter $matter): JsonResponse
    {
        $validated = $request->validate([
            'side' => ['required', Rule::in([Exhibit::OURS, Exhibit::ADVERSE])],
            'parent' => ['nullable', 'string', 'max:20'],
        ]);

        return response()->json(['marking' => $this->markings->next($matter, $validated['side'], $validated['parent'] ?? null)]);
    }

    public function store(Request $request, Matter $matter): JsonResponse
    {
        Gate::authorize('work-matters');
        $validated = $request->validate($this->rules($matter, true));
        $validated['marking'] ??= $this->markings->next($matter, $validated['side'], $validated['parent'] ?? null);
        unset($validated['parent']);

        $exhibit = $this->saving(fn () => Exhibit::create([
            ...$validated,
            'firm_id' => $matter->firm_id,
            'matter_id' => $matter->id,
            'created_by' => $request->user()->id,
        ]));

        return response()->json($this->present($exhibit->load('file:id,original_name')), 201);
    }

    public function update(Request $request, Exhibit $exhibit): JsonResponse
    {
        Gate::authorize('work-matters');
        $validated = $request->validate($this->rules($exhibit->matter, false));
        unset($validated['parent']);
        if (isset($validated['status']) && in_array($validated['status'], ['marked', 'offered'], true)) {
            $validated['ruled_on'] = null;
        }

        $this->saving(fn () => $exhibit->fill($validated)->save());

        return response()->json($this->present($exhibit->load('file:id,original_name')));
    }

    /** One ruling for several exhibits: "Exhibits A to F are admitted; G is denied." */
    public function bulkStatus(Request $request, Matter $matter): JsonResponse
    {
        Gate::authorize('work-matters');
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer', Rule::exists('exhibits', 'id')->where('matter_id', $matter->id)],
            'status' => ['required', Rule::in(Exhibit::STATUSES)],
            'ruled_on' => ['nullable', 'date', 'before_or_equal:today'],
            'ruling' => ['nullable', 'string', 'max:5000'],
        ]);

        $ruled = in_array($validated['status'], ['admitted', 'denied'], true);
        DB::transaction(function () use ($validated, $matter, $ruled) {
            Exhibit::where('matter_id', $matter->id)->whereIn('id', $validated['ids'])->get()->each(fn (Exhibit $e) => $e->fill([
                'status' => $validated['status'],
                'ruled_on' => $ruled ? ($validated['ruled_on'] ?? today()->toDateString()) : null,
                'ruling' => $validated['ruling'] ?? $e->ruling,
            ])->save());
        });

        return response()->json(['updated' => count($validated['ids'])]);
    }

    public function destroy(Exhibit $exhibit): JsonResponse
    {
        Gate::authorize('work-matters');
        abort_if(in_array($exhibit->status, ['admitted', 'denied'], true), 422, 'The court has ruled on this exhibit; mark it withdrawn instead.');
        $exhibit->delete();

        return response()->json(null, 204);
    }

    /** The exhibit list as a spreadsheet, for pre-trial briefs and trial binders. */
    public function csv(Matter $matter): StreamedResponse
    {
        $exhibits = $this->markings->sort(Exhibit::where('matter_id', $matter->id)->with('file:id,original_name')->get());

        return response()->streamDownload(function () use ($exhibits, $matter) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ["Exhibit list: {$this->safe($matter->title)}", $matter->case_number ?? ''], escape: '');
            fputcsv($out, ['Side', 'Exhibit', 'Description', 'Purpose', 'Witness', 'Marked on', 'Status', 'Objection', 'Ruling', 'Ruled on', 'File'], escape: '');
            foreach ($exhibits as $e) {
                fputcsv($out, array_map(fn ($v) => $this->safe((string) $v), [
                    $e->side === Exhibit::OURS ? 'Ours' : 'Other side', $e->marking, $e->description, $e->purpose, $e->witness,
                    $e->marked_on?->toDateString(), ucfirst($e->status), $e->objection, $e->ruling, $e->ruled_on?->toDateString(), $e->file?->original_name,
                ]), escape: '');
            }
            fclose($out);
        }, "exhibits-{$matter->reference}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function previewOffer(Request $request, Matter $matter): JsonResponse
    {
        Gate::authorize('work-matters');

        return response()->json(['text' => $this->offer->assemble($matter, $this->counsel($request, $matter), $this->offered($request, $matter), $request->only('place'))]);
    }

    /** Draft the Formal Offer of Evidence as a document on the matter, ready to edit and export to Word. */
    public function storeOffer(Request $request, Matter $matter): JsonResponse
    {
        Gate::authorize('work-matters');
        $text = $this->offer->assemble($matter, $this->counsel($request, $matter), $this->offered($request, $matter), $request->only('place'));

        $document = DB::transaction(function () use ($matter, $request, $text) {
            $document = $matter->documents()->create([
                'firm_id' => $matter->firm_id,
                'title' => "Formal Offer of Evidence — {$matter->title}",
                'created_by' => $request->user()->id,
            ]);
            app(CreateDocumentVersion::class)->execute($document, $text, $request->user(), 'Drafted from the exhibit list');

            return $document;
        });

        return response()->json(['id' => $document->id, 'title' => $document->title], 201);
    }

    private function offered(Request $request, Matter $matter)
    {
        $validated = $request->validate([
            'ids' => ['nullable', 'array', 'max:500'],
            'ids.*' => ['integer'],
            'place' => ['nullable', 'string', 'max:100'],
            'counsel_id' => ['nullable', 'integer'],
        ]);
        $exhibits = isset($validated['ids'])
            ? Exhibit::where('matter_id', $matter->id)->where('side', Exhibit::OURS)->whereIn('id', $validated['ids'])->get()
            : $this->offer->offerable($matter);

        if ($exhibits->isEmpty()) {
            throw ValidationException::withMessages(['ids' => 'Mark at least one of your exhibits first.']);
        }

        return $exhibits;
    }

    private function counsel(Request $request, Matter $matter): User
    {
        $id = $request->integer('counsel_id') ?: $matter->responsible_lawyer_id;
        $lawyer = $id ? User::where('is_active', true)->find($id) : null;

        return $lawyer && $lawyer->role->isLawyer() ? $lawyer : $request->user();
    }

    private function rules(Matter $matter, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'side' => [$required, Rule::in([Exhibit::OURS, Exhibit::ADVERSE])],
            'marking' => ['sometimes', 'nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9]+(-[A-Za-z0-9]+)*$/'],
            'parent' => ['nullable', 'string', 'max:20'],
            'description' => [$required, 'string', 'max:1000'],
            'purpose' => ['nullable', 'string', 'max:5000'],
            'witness' => ['nullable', 'string', 'max:255'],
            'matter_file_id' => ['nullable', 'integer', Rule::exists('matter_files', 'id')->where('matter_id', $matter->id)],
            'marked_on' => ['nullable', 'date', 'before_or_equal:today'],
            'status' => ['sometimes', Rule::in(Exhibit::STATUSES)],
            'objection' => ['nullable', 'string', 'max:5000'],
            'ruling' => ['nullable', 'string', 'max:5000'],
            'ruled_on' => ['nullable', 'date', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /** A marking already used on that side is a validation error, not a server error. */
    private function saving(callable $save): mixed
    {
        try {
            return DB::transaction($save);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['marking' => 'That marking is already used on this side.']);
        }
    }

    private function present(Exhibit $e): array
    {
        return [
            'id' => $e->id,
            'side' => $e->side,
            'marking' => $e->marking,
            'description' => $e->description,
            'purpose' => $e->purpose,
            'witness' => $e->witness,
            'matter_file_id' => $e->matter_file_id,
            'file_name' => $e->file?->original_name,
            'marked_on' => $e->marked_on?->toDateString(),
            'status' => $e->status,
            'objection' => $e->objection,
            'ruling' => $e->ruling,
            'ruled_on' => $e->ruled_on?->toDateString(),
            'notes' => $e->notes,
        ];
    }

    private function safe(string $value): string
    {
        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }
}
