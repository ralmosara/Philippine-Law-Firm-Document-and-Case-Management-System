<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Imports\DataImports;
use App\Domain\Imports\Models\DataImport;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Bringing existing clients, matters, deadlines and trust balances in from spreadsheets. */
class ImportController extends Controller
{
    public function __construct(private readonly DataImports $imports) {}

    /** What can be imported, in order, with each type's columns. */
    public function types(Request $request): JsonResponse
    {
        return response()->json(collect(DataImports::TYPES)->keys()->map(function (string $type) use ($request) {
            $importer = $this->imports->importer($type);

            return [
                'type' => $type,
                'label' => $importer->label(),
                'allowed' => $request->user()->can($importer->ability()),
                'columns' => collect($importer->columns())->map(fn ($c, $key) => [
                    'key' => $key,
                    'label' => $c['label'],
                    'required' => $c['required'] ?? false,
                    'example' => $c['example'],
                    'hint' => $c['hint'] ?? null,
                ])->values(),
            ];
        })->values());
    }

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('manage-firm') || $request->user()->can('manage-finances'), 403);

        return response()->json(DataImport::query()->with('creator:id,name')->latest('id')->limit(50)
            ->get(['id', 'type', 'filename', 'status', 'summary', 'created_by', 'committed_at', 'undone_at', 'created_at'])
            ->map(fn (DataImport $i) => $this->summary($i)));
    }

    /** A CSV with the headers and one example row, which opens in Excel. */
    public function template(string $type): StreamedResponse
    {
        $columns = $this->imports->importer($type)->columns();

        return response()->streamDownload(function () use ($columns) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, array_column($columns, 'label'), escape: '');
            fputcsv($out, array_column($columns, 'example'), escape: '');
            fclose($out);
        }, "import-{$type}-template.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', Rule::in(array_keys(DataImports::TYPES))],
            'file' => ['required', 'file', 'max:5120', 'mimes:csv,txt,xlsx'],
        ]);
        Gate::authorize($this->imports->importer($validated['type'])->ability());

        $import = $this->imports->preview($validated['type'], $request->file('file'), $request->user());

        return response()->json($this->detail($import->load('creator:id,name')), 201);
    }

    public function show(DataImport $import): JsonResponse
    {
        Gate::authorize($this->imports->importer($import->type)->ability());

        return response()->json($this->detail($import->load('creator:id,name')));
    }

    public function commit(Request $request, DataImport $import): JsonResponse
    {
        Gate::authorize($this->imports->importer($import->type)->ability());

        return response()->json($this->detail($this->imports->commit($import, $request->user())->load('creator:id,name')));
    }

    public function undo(Request $request, DataImport $import): JsonResponse
    {
        Gate::authorize($this->imports->importer($import->type)->ability());

        $result = $this->imports->undo($import, $request->user());

        return response()->json([...$this->detail($result['import']->load('creator:id,name')), 'undone' => $result['undone'], 'kept' => $result['kept']]);
    }

    private function summary(DataImport $import): array
    {
        return [
            'id' => $import->id,
            'type' => $import->type,
            'label' => $this->imports->importer($import->type)->label(),
            'filename' => $import->filename,
            'status' => $import->status,
            'summary' => $import->summary,
            'can_undo' => $import->canUndo(),
            'created_by' => $import->creator?->name,
            'committed_at' => $import->committed_at?->toIso8601String(),
            'undone_at' => $import->undone_at?->toIso8601String(),
            'created_at' => $import->created_at?->toIso8601String(),
        ];
    }

    private function detail(DataImport $import): array
    {
        return [
            ...$this->summary($import),
            'columns' => collect($this->imports->importer($import->type)->columns())->map(fn ($c, $key) => ['key' => $key, 'label' => $c['label']])->values(),
            'rows' => collect($import->rows)->map(fn (array $row) => [
                'line' => $row['line'],
                'status' => $row['status'],
                'messages' => array_values($row['messages']),
                'warnings' => $row['warnings'] ?? [],
                'raw' => $row['raw'],
                'created' => $row['created'] ?? false,
            ]),
        ];
    }
}
