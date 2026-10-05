<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Compliance\Services\CounselCredentials;
use App\Domain\Documents\Actions\CreateDocumentVersion;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Pleadings\DocxWriter;
use App\Domain\Documents\Pleadings\PleadingAssembler;
use App\Domain\Matters\Models\Firm;
use App\Domain\Matters\Models\Matter;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Assembling pleadings from a matter, and exporting any document to Word. */
class PleadingController extends Controller
{
    public function __construct(private readonly PleadingAssembler $assembler) {}

    public function options(): JsonResponse
    {
        return response()->json([
            'types' => collect(PleadingAssembler::TYPES)->map(fn ($t, $value) => ['value' => $value, 'label' => $t['label'], 'initiatory' => $t['initiatory']])->values(),
            'client_roles' => array_map(fn ($r) => ['value' => $r, 'label' => ucfirst($r)], PleadingAssembler::CLIENT_ROLES),
            'papers' => collect(DocxWriter::PAPERS)->map(fn ($p, $value) => ['value' => $value, 'label' => $p['label']])->values(),
        ]);
    }

    public function preview(Request $request, Matter $matter): JsonResponse
    {
        Gate::authorize('work-matters');

        $counsel = $this->counsel($request, $matter);

        return response()->json([
            'text' => $this->assembler->assemble($matter, $counsel, $this->pleadingOptions($request)),
            // Shown above the preview: a PTR or IBP not for this year, or not recorded.
            'warnings' => array_map(fn ($p) => "{$counsel->name}: {$p}", app(CounselCredentials::class)->problems($counsel)),
        ]);
    }

    public function store(Request $request, Matter $matter): JsonResponse
    {
        Gate::authorize('work-matters');
        $options = $this->pleadingOptions($request);
        $text = $this->assembler->assemble($matter, $this->counsel($request, $matter), $options);
        $title = Str::title(mb_strtolower(trim($options['title'] ?? '') ?: PleadingAssembler::TYPES[$options['type']]['label']));

        $document = DB::transaction(function () use ($matter, $request, $text, $title) {
            $document = $matter->documents()->create([
                'firm_id' => $matter->firm_id,
                'title' => "{$title} — {$matter->title}",
                'created_by' => $request->user()->id,
            ]);
            app(CreateDocumentVersion::class)->execute($document, $text, $request->user(), 'Assembled as a pleading');

            return $document;
        });

        return response()->json(['id' => $document->id, 'title' => $document->title], 201);
    }

    /** The latest version of a document as a Word file, in the firm's pleading format. */
    public function docx(Document $document, DocxWriter $writer): Response
    {
        Gate::authorize('practice-law');
        $version = $document->versions()->latest('version_number')->firstOrFail();
        $firm = Firm::findOrFail($document->firm_id);

        $bytes = $writer->write((string) $version->content, [
            'paper' => $firm->pleading_paper,
            'font' => $firm->pleading_font,
            'size' => $firm->pleading_font_size,
            'title' => $document->title,
        ]);
        $filename = (Str::slug($document->title) ?: 'document').'.docx';

        return response($bytes, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /** @return array{type: string, title: ?string, body: ?string, prayer: ?string, place: ?string, verification?: bool, certification?: bool, service: bool} */
    private function pleadingOptions(Request $request): array
    {
        $validated = $request->validate([
            'type' => ['required', Rule::in(array_keys(PleadingAssembler::TYPES))],
            'title' => ['nullable', 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:100000'],
            'prayer' => ['nullable', 'string', 'max:10000'],
            'place' => ['nullable', 'string', 'max:100'],
            'counsel_id' => ['nullable', 'integer'],
            'verification' => ['nullable', 'boolean'],
            'certification' => ['nullable', 'boolean'],
            'service' => ['nullable', 'boolean'],
        ]);

        return array_filter([
            'type' => $validated['type'],
            'title' => $validated['title'] ?? null,
            'body' => $validated['body'] ?? null,
            'prayer' => $validated['prayer'] ?? null,
            'place' => $validated['place'] ?? null,
            'verification' => $validated['verification'] ?? null,
            'certification' => $validated['certification'] ?? null,
            'service' => $validated['service'] ?? true,
        ], fn ($v) => $v !== null);
    }

    /** The signing lawyer: chosen, else the matter's responsible lawyer, else whoever is drafting. */
    private function counsel(Request $request, Matter $matter): User
    {
        $id = $request->integer('counsel_id') ?: $matter->responsible_lawyer_id;
        $lawyer = $id ? User::where('is_active', true)->find($id) : null;

        return $lawyer && $lawyer->role->isLawyer() ? $lawyer : $request->user();
    }
}
