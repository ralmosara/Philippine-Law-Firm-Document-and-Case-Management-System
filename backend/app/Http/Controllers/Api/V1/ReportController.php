<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Analytics\Reports;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Management reports, as JSON for the screen or CSV for spreadsheets. */
class ReportController extends Controller
{
    /** CSV columns per report: [key => heading]. Money columns are pesos in the CSV. */
    private const COLUMNS = [
        'aged-receivables' => ['client' => 'Client', 'invoices' => 'Invoices', 'current' => 'Not yet due', 'd1_30' => '1-30 days', 'd31_60' => '31-60 days', 'd61_90' => '61-90 days', 'd90_plus' => 'Over 90 days', 'total' => 'Total outstanding'],
        'collections' => ['lawyer' => 'Responsible lawyer', 'payments' => 'Payments', 'received' => 'Cash received', 'withheld' => 'Tax withheld (2307)', 'total' => 'Total collected'],
        'matter-profitability' => ['reference' => 'Reference', 'title' => 'Matter', 'client' => 'Client', 'lawyer' => 'Lawyer', 'status' => 'Status', 'recorded' => 'Time recorded', 'billed' => 'Fees billed', 'collected' => 'Fees collected', 'unbilled' => 'Unbilled time', 'expenses' => 'Expenses', 'collection_rate' => 'Collection rate %'],
    ];

    private const MONEY = ['current', 'd1_30', 'd31_60', 'd61_90', 'd90_plus', 'total', 'received', 'withheld', 'expenses', 'recorded', 'billed', 'collected', 'unbilled'];

    public function show(Request $request, string $report, Reports $reports): JsonResponse|StreamedResponse
    {
        Gate::authorize('manage-finances');

        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'as_of' => ['nullable', 'date'],
            'format' => ['nullable', Rule::in(['json', 'csv'])],
        ]);

        $data = match ($report) {
            'aged-receivables' => $reports->agedReceivables(CarbonImmutable::parse($validated['as_of'] ?? 'today')),
            'collections' => $reports->collections(
                CarbonImmutable::parse($validated['from'] ?? now()->startOfYear()->toDateString()),
                CarbonImmutable::parse($validated['to'] ?? 'today'),
            ),
            'matter-profitability' => $reports->matterProfitability(),
            default => abort(404),
        };

        if (($validated['format'] ?? 'json') === 'csv') {
            return $this->csv($report, $data);
        }

        return response()->json($data);
    }

    private function csv(string $report, array $data): StreamedResponse
    {
        $columns = self::COLUMNS[$report];
        $filename = $report.'-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($columns, $data) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM, so Excel reads UTF-8 names correctly
            fputcsv($out, array_values($columns), escape: '');

            foreach ($data['rows'] as $row) {
                fputcsv($out, $this->line($columns, $row), escape: '');
            }
            fputcsv($out, $this->line($columns, [array_key_first($columns) => 'TOTAL'] + $data['totals']), escape: '');

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return list<string> */
    private function line(array $columns, array $row): array
    {
        return array_map(function (string $key) use ($row) {
            $value = $row[$key] ?? '';

            return in_array($key, self::MONEY, true) && is_int($value)
                ? number_format($value / 100, 2, '.', '')
                : $this->safeCell($value);
        }, array_keys($columns));
    }

    /** Neutralise spreadsheet formula injection from names typed by users. */
    private function safeCell(mixed $value): string
    {
        $value = (string) $value;

        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }
}
