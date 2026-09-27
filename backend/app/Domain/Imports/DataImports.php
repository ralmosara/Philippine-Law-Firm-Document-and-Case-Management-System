<?php

namespace App\Domain\Imports;

use App\Domain\Imports\Importers\ClientImporter;
use App\Domain\Imports\Importers\DeadlineImporter;
use App\Domain\Imports\Importers\Importer;
use App\Domain\Imports\Importers\MatterImporter;
use App\Domain\Imports\Importers\TrustBalanceImporter;
use App\Domain\Imports\Models\DataImport;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Bringing a firm's existing records in from spreadsheets, in three steps:
 * preview (nothing is written but the preview), commit (ready rows only,
 * re-checked, all or nothing), and undo (for records nobody has used yet).
 */
class DataImports
{
    public const READY = 'ready';

    public const DUPLICATE = 'duplicate';

    public const ERROR = 'error';

    /** @var array<string, class-string<Importer>> in the order a firm should import */
    public const TYPES = [
        'clients' => ClientImporter::class,
        'matters' => MatterImporter::class,
        'deadlines' => DeadlineImporter::class,
        'trust_balances' => TrustBalanceImporter::class,
    ];

    public function __construct(private readonly SpreadsheetReader $reader) {}

    public function importer(string $type): Importer
    {
        $class = self::TYPES[$type] ?? throw ValidationException::withMessages(['type' => 'Unknown import type.']);

        return app($class);
    }

    public function preview(string $type, UploadedFile $file, User $by): DataImport
    {
        $importer = $this->importer($type);
        $sheet = $this->reader->read($file->getRealPath(), $file->getClientOriginalName());

        $mapping = $importer->mapHeaders(array_keys($sheet[0] ?? []));
        if ($mapping['missing'] !== []) {
            throw ValidationException::withMessages(['file' => 'Missing column'.(count($mapping['missing']) > 1 ? 's' : '').': '.implode(', ', $mapping['missing']).'. Download the template to see the expected headers.']);
        }

        $importer->prepare($by->firm_id);
        $rows = [];
        foreach ($sheet as $i => $cells) {
            $raw = [];
            foreach ($mapping['map'] as $header => $key) {
                $raw[$key] = $cells[$header] ?? '';
            }
            $rows[] = ['line' => $i + 2, 'raw' => $raw, ...$this->checked($importer, $raw)];
        }

        return DataImport::create([
            'firm_id' => $by->firm_id,
            'type' => $type,
            'filename' => mb_substr($file->getClientOriginalName(), 0, 255),
            'status' => DataImport::PREVIEWED,
            'rows' => $rows,
            'summary' => [...$this->count($rows), 'ignored_columns' => $mapping['ignored']],
            'created_by' => $by->id,
        ]);
    }

    /**
     * Create the ready rows. Everything is checked again first (someone may
     * have added a client since the preview); if a row is no longer ready
     * the commit stops and shows the new preview instead of half-importing.
     */
    public function commit(DataImport $import, User $by): DataImport
    {
        if ($import->status !== DataImport::PREVIEWED) {
            throw ValidationException::withMessages(['import' => 'This import has already been committed or undone.']);
        }

        $importer = $this->importer($import->type);

        $stale = DB::transaction(function () use ($import, $importer, $by) {
            $import = DataImport::whereKey($import->id)->lockForUpdate()->firstOrFail();
            if ($import->status !== DataImport::PREVIEWED) {
                throw ValidationException::withMessages(['import' => 'This import has already been committed or undone.']);
            }
            $importer->prepare($by->firm_id);

            $rows = $import->rows;
            $changed = false;
            foreach ($rows as $i => $row) {
                $fresh = $this->checked($importer, $row['raw']);
                if ($row['status'] === self::READY && $fresh['status'] !== self::READY) {
                    $changed = true;
                }
                $rows[$i] = [...$row, ...$fresh];
            }

            if ($changed) {
                return $rows; // saved below, outside the rolled-back transaction
            }

            $created = [];
            foreach ($rows as $i => $row) {
                if ($row['status'] === self::READY) {
                    $created[] = $importer->create($row['values'], $by)->getKey();
                    $rows[$i]['created'] = true;
                }
            }

            $import->forceFill([
                'rows' => $rows,
                'status' => DataImport::COMMITTED,
                'created_ids' => $created,
                'committed_at' => now(),
                'summary' => [...$import->summary, ...$this->count($rows), 'created' => count($created)],
            ])->save();

            return null;
        });

        if ($stale !== null) {
            $import->forceFill(['rows' => $stale, 'summary' => [...$import->summary, ...$this->count($stale)]])->save();

            throw ValidationException::withMessages(['import' => 'Some rows are no longer ready (records were added meanwhile). Review the updated preview and commit again.']);
        }

        return $import->refresh();
    }

    /**
     * Take back what the import created, newest first. Records that have
     * been used since (a matter with time on it, say) stay, and are listed.
     *
     * @return array{import: DataImport, undone: int, kept: list<string>}
     */
    public function undo(DataImport $import, User $by): array
    {
        if (! $import->canUndo()) {
            throw ValidationException::withMessages(['import' => 'Only a committed import from the last '.DataImport::UNDO_DAYS.' days can be undone.']);
        }

        $importer = $this->importer($import->type);
        $class = $importer->modelClass();
        $undone = 0;
        $kept = [];

        DB::transaction(function () use ($import, $importer, $class, $by, &$undone, &$kept) {
            $models = $class::query()->whereKey($import->created_ids ?? [])->orderByDesc('id')->lockForUpdate()->get();

            foreach ($models as $model) {
                $reason = $importer->undo($model, $by);
                $reason === null ? $undone++ : $kept[] = $reason;
            }

            $import->forceFill([
                'status' => DataImport::UNDONE,
                'undone_at' => now(),
                'summary' => [...$import->summary, 'undone' => $undone, 'kept' => $kept],
            ])->save();
        });

        return ['import' => $import, 'undone' => $undone, 'kept' => $kept];
    }

    /**
     * @param  array<string, string>  $raw
     * @return array{status: string, messages: list<string>, warnings: list<string>, values: array<string, mixed>}
     */
    private function checked(Importer $importer, array $raw): array
    {
        $result = $importer->check($raw);

        return [
            'status' => match (true) {
                $result['errors'] !== [] => self::ERROR,
                $result['duplicate'] !== null => self::DUPLICATE,
                default => self::READY,
            },
            'messages' => $result['errors'] ?: array_filter([$result['duplicate']]),
            'warnings' => $result['warnings'],
            'values' => $result['values'],
        ];
    }

    /** @return array{total: int, ready: int, duplicate: int, error: int} */
    private function count(array $rows): array
    {
        $statuses = array_count_values(array_column($rows, 'status'));

        return [
            'total' => count($rows),
            'ready' => $statuses[self::READY] ?? 0,
            'duplicate' => $statuses[self::DUPLICATE] ?? 0,
            'error' => $statuses[self::ERROR] ?? 0,
        ];
    }
}
