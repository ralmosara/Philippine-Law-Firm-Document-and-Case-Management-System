<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Analytics\BooksOfAccounts;
use App\Domain\Matters\Models\Firm;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Support\Pdf\PdfRenderer;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** A month's books of accounts, for the accountant: CSV to post, PDF to print on loose leaf. */
class BooksController extends Controller
{
    public function __construct(private readonly BooksOfAccounts $books) {}

    /** Each book's entries and totals for the month, to check before downloading. */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('manage-finances');
        $month = $this->month($request);

        return response()->json([
            'month' => $month->format('Y-m'),
            'books' => collect(BooksOfAccounts::BOOKS)->map(function ($title, $key) use ($month) {
                $book = $this->books->book($key, $month);

                return ['key' => $key, 'title' => $title, 'entries' => count($book['rows']), 'totals' => $book['totals'], 'labels' => array_intersect_key($book['columns'], $book['totals'])];
            })->values(),
        ]);
    }

    public function show(Request $request, string $book, PdfRenderer $pdf): Response|StreamedResponse
    {
        Gate::authorize('manage-finances');
        abort_unless(array_key_exists($book, BooksOfAccounts::BOOKS), 404);
        $validated = $request->validate(['format' => ['required', Rule::in(['csv', 'pdf'])]]);
        $month = $this->month($request);
        $data = $this->books->book($book, $month);
        $filename = "{$book}-{$month->format('Y-m')}";
        AuditLog::record('books_exported', $request->user()->firm_id, $request->user(), null, ['book' => $book, 'month' => $month->format('Y-m'), 'format' => $validated['format']]);

        if ($validated['format'] === 'pdf') {
            return $pdf->download('pdf.book', [...$data, 'month' => $month, 'firm' => Firm::findOrFail($request->user()->firm_id)], $filename, 'a4-landscape');
        }

        return response()->streamDownload(function () use ($data) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");   // so Excel reads the names as UTF-8
            fputcsv($out, array_values($data['columns']), escape: '');
            $peso = fn ($v) => number_format($v / 100, 2, '.', '');
            foreach ($data['rows'] as $row) {
                fputcsv($out, array_map(fn ($key) => in_array($key, $data['money_columns'], true) ? $peso((int) ($row[$key] ?? 0)) : (string) ($row[$key] ?? ''), array_keys($data['columns'])), escape: '');
            }
            fputcsv($out, array_map(fn ($key) => $key === array_key_first($data['columns']) ? 'TOTAL' : (isset($data['totals'][$key]) ? $peso($data['totals'][$key]) : ''), array_keys($data['columns'])), escape: '');
            fclose($out);
        }, "{$filename}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function month(Request $request): CarbonImmutable
    {
        $validated = $request->validate(['month' => ['required', 'date_format:Y-m']]);

        return CarbonImmutable::createFromFormat('!Y-m', $validated['month']);
    }
}
