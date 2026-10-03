<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Deadlines\Enums\DeadlineStatus;
use App\Domain\Deadlines\Models\MatterDeadline;
use App\Domain\Deadlines\Services\DeadlineScheduler;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\MatterFile;
use App\Domain\Filing\EFilingPackager;
use App\Domain\Filing\Models\EFiling;
use App\Domain\Matters\Models\Matter;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** Preparing a pleading and its annexes for electronic filing, and recording the filing. */
class EFilingController extends Controller
{
    public function index(Matter $matter): JsonResponse
    {
        return response()->json([
            'via' => EFiling::VIA,
            'max_mb' => (int) config('services.efiling.max_mb', 25),
            'data' => EFiling::where('matter_id', $matter->id)
                ->with(['package:id,original_name,size_bytes', 'acknowledgmentFile:id,original_name', 'deadline:id,title,due_date', 'creator:id,name', 'filer:id,name'])
                ->latest('id')->get()->map(fn (EFiling $f) => $this->present($f)),
        ]);
    }

    public function store(Request $request, Matter $matter, EFilingPackager $packager): JsonResponse
    {
        Gate::authorize('work-matters');
        $validated = $request->validate([
            'document_id' => ['nullable', 'integer', Rule::exists('documents', 'id')->where('matter_id', $matter->id)],
            // Or the pleading as an uploaded PDF, e.g. the signed copy.
            'main_file_id' => ['nullable', 'integer', 'prohibits:document_id', Rule::exists('matter_files', 'id')->where('matter_id', $matter->id)->whereNull('deleted_at')],
            'annexes' => ['present', 'array', 'max:60'],
            'annexes.*.file_id' => ['required', 'integer', 'distinct', Rule::exists('matter_files', 'id')->where('matter_id', $matter->id)->whereNull('deleted_at')],
            'annexes.*.description' => ['nullable', 'string', 'max:200'],
            'annex_style' => ['nullable', Rule::in(['letters', 'numbers'])],
            'separators' => ['nullable', 'boolean'],
            'title' => ['nullable', 'string', 'max:250'],
        ]);
        @set_time_limit(300);

        $document = isset($validated['document_id']) ? Document::with('latestVersion')->findOrFail($validated['document_id']) : null;
        $mainFile = isset($validated['main_file_id']) ? MatterFile::findOrFail($validated['main_file_id']) : null;
        $files = MatterFile::whereIn('id', array_column($validated['annexes'], 'file_id'))->get()->keyBy('id');
        $annexes = array_map(fn (array $a) => ['file' => $files[$a['file_id']], 'description' => $a['description'] ?? null], $validated['annexes']);

        $filing = $packager->build($matter, $document, $annexes, $request->user(), [
            'title' => trim((string) ($validated['title'] ?? '')) ?: ($document?->title ?? ($mainFile ? pathinfo($mainFile->original_name, PATHINFO_FILENAME) : 'Annexes')),
            'annex_style' => $validated['annex_style'] ?? 'letters',
            'separators' => $validated['separators'] ?? true,
            'main_file' => $mainFile,
        ]);

        return response()->json($this->present($filing->load(['package:id,original_name,size_bytes', 'creator:id,name'])), 201);
    }

    /** Record that it was filed: when, how, where, the court's reference, and the deadline it meets. */
    public function filed(Request $request, EFiling $eFiling, DeadlineScheduler $scheduler): JsonResponse
    {
        Gate::authorize('work-matters');
        $validated = $request->validate([
            'filed_at' => ['required', 'date', 'before_or_equal:now'],
            'filed_via' => ['required', Rule::in(array_keys(EFiling::VIA))],
            'filed_to' => ['nullable', 'string', 'max:255'],
            'filing_reference' => ['nullable', 'string', 'max:100'],
            'deadline_id' => ['nullable', 'integer', Rule::exists('matter_deadlines', 'id')->where('matter_id', $eFiling->matter_id)],
        ]);

        DB::transaction(function () use ($eFiling, $validated, $request, $scheduler) {
            $eFiling->forceFill([
                'status' => $eFiling->status === 'acknowledged' ? 'acknowledged' : 'filed',
                'filed_at' => $validated['filed_at'],
                'filed_via' => $validated['filed_via'],
                'filed_to' => $validated['filed_to'] ?? null,
                'filing_reference' => $validated['filing_reference'] ?? null,
                'deadline_id' => $validated['deadline_id'] ?? null,
                'filed_by' => $request->user()->id,
            ])->save();

            // The deadline this filing meets is done.
            if (! empty($validated['deadline_id'])) {
                $deadline = MatterDeadline::findOrFail($validated['deadline_id']);
                if ($deadline->status === DeadlineStatus::Pending) {
                    $scheduler->complete($deadline, $request->user(), "Filed electronically: {$eFiling->title}");
                }
            }
        });

        return response()->json($this->present($eFiling->refresh()->load(['package:id,original_name,size_bytes', 'deadline:id,title,due_date', 'filer:id,name'])));
    }

    /** The court's acknowledgment: its reference, and the receipt e-mail or stamp saved to the matter's files. */
    public function acknowledged(Request $request, EFiling $eFiling): JsonResponse
    {
        Gate::authorize('work-matters');
        abort_if($eFiling->status === 'prepared', 422, 'Record the filing first.');
        $validated = $request->validate([
            'acknowledged_at' => ['required', 'date', 'before_or_equal:now'],
            'acknowledgment' => ['nullable', 'string', 'max:500'],
            'acknowledgment_file_id' => ['nullable', 'integer', Rule::exists('matter_files', 'id')->where('matter_id', $eFiling->matter_id)],
        ]);
        $eFiling->forceFill([...$validated, 'status' => 'acknowledged'])->save();

        return response()->json($this->present($eFiling->refresh()->load(['package:id,original_name,size_bytes', 'acknowledgmentFile:id,original_name', 'deadline:id,title,due_date', 'filer:id,name'])));
    }

    /** Files that can go into a package: PDFs and images uploaded to the matter. */
    public function sources(Matter $matter): JsonResponse
    {
        return response()->json([
            'documents' => $matter->documents()->select(['id', 'title', 'status', 'updated_at'])->latest('updated_at')->get(),
            'files' => MatterFile::where('matter_id', $matter->id)
                ->where(fn ($q) => $q->where('mime_type', 'application/pdf')->orWhere('mime_type', 'like', 'image/%'))
                ->latest('id')->get(['id', 'original_name', 'description', 'mime_type', 'size_bytes', 'created_at']),
            'deadlines' => MatterDeadline::where('matter_id', $matter->id)->where('status', DeadlineStatus::Pending->value)
                ->orderBy('due_date')->get(['id', 'title', 'due_date', 'kind']),
        ]);
    }

    private function present(EFiling $f): array
    {
        return [
            'id' => $f->id,
            'title' => $f->title,
            'status' => $f->status,
            'items' => $f->items,
            'page_count' => $f->page_count,
            'size_bytes' => $f->size_bytes,
            'checks' => $f->checks ?? [],
            'package' => $f->relationLoaded('package') && $f->package ? ['id' => $f->package->id, 'name' => $f->package->original_name] : null,
            'filed_at' => $f->filed_at?->toIso8601String(),
            'filed_via' => $f->filed_via,
            'filed_via_label' => $f->filed_via ? EFiling::VIA[$f->filed_via] ?? $f->filed_via : null,
            'filed_to' => $f->filed_to,
            'filing_reference' => $f->filing_reference,
            'filed_by' => $f->relationLoaded('filer') ? $f->filer?->name : null,
            'acknowledged_at' => $f->acknowledged_at?->toIso8601String(),
            'acknowledgment' => $f->acknowledgment,
            'acknowledgment_file' => $f->relationLoaded('acknowledgmentFile') && $f->acknowledgmentFile ? ['id' => $f->acknowledgmentFile->id, 'name' => $f->acknowledgmentFile->original_name] : null,
            'deadline' => $f->relationLoaded('deadline') && $f->deadline ? ['id' => $f->deadline->id, 'title' => $f->deadline->title, 'due_date' => $f->deadline->due_date->toDateString()] : null,
            'created_by' => $f->relationLoaded('creator') ? $f->creator?->name : null,
            'created_at' => $f->created_at?->toIso8601String(),
        ];
    }
}
