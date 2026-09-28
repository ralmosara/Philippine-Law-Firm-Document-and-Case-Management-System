<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Matters\Models\Firm;
use App\Domain\Tax\BirCalendar;
use App\Domain\Tax\Models\TaxFiling;
use App\Domain\Tax\TaxReports;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** The firm's own BIR compliance: quarterly figures, the SAWT and the filing calendar. */
class TaxController extends Controller
{
    public function __construct(private readonly TaxReports $reports, private readonly BirCalendar $calendar) {}

    public function quarter(Request $request): JsonResponse
    {
        Gate::authorize('manage-finances');
        [$year, $quarter] = $this->period($request);
        $firm = Firm::findOrFail($request->user()->firm_id);

        return response()->json([
            ...$this->reports->quarter($year, $quarter),
            'firm' => $firm->only(['name', 'tin', 'vat_registered', 'taxpayer_type', 'withholding_atc', 'has_employees']),
            'sawt' => $this->reports->sawt($firm, $year, $quarter),
        ]);
    }

    /** The SAWT as a CSV in the form's columns, for the accountant or the BIR's data entry tool. */
    public function sawtCsv(Request $request): StreamedResponse
    {
        Gate::authorize('manage-finances');
        [$year, $quarter] = $this->period($request);
        $firm = Firm::findOrFail($request->user()->firm_id);
        $rows = $this->reports->sawt($firm, $year, $quarter);

        return response()->streamDownload(function () use ($rows, $firm, $year, $quarter) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ["Summary Alphalist of Withholding Taxes, {$year} Q{$quarter}", $firm->name, 'TIN '.($firm->tin ?? '')], escape: '');
            fputcsv($out, ['Seq', 'TIN of withholding agent', 'Registered name', 'ATC', 'Nature of income payment', 'Amount of income payment', 'Tax rate (%)', 'Amount of tax withheld', 'Form 2307 received'], escape: '');
            foreach ($rows as $i => $row) {
                fputcsv($out, [
                    $i + 1, $row['payor_tin'] ?? '', $this->safe($row['payor_name']), $row['atc'], $row['nature'],
                    number_format($row['income_payment_cents'] / 100, 2, '.', ''), $row['rate'] ?? '',
                    number_format($row['tax_withheld_cents'] / 100, 2, '.', ''), $row['with_2307'] ? 'Yes' : 'NO: do not claim until received',
                ], escape: '');
            }
            fclose($out);
        }, "sawt-{$year}-q{$quarter}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function filings(Request $request): JsonResponse
    {
        Gate::authorize('manage-finances');
        $year = (int) ($request->validate(['year' => ['nullable', 'integer', 'min:2020', 'max:2100']])['year'] ?? now()->year);
        $this->calendar->ensureYear(Firm::findOrFail($request->user()->firm_id), $year);

        return response()->json([
            'year' => $year,
            'forms' => BirCalendar::FORMS,
            'filings' => TaxFiling::query()
                ->where(fn ($q) => $q->where('period', 'like', "{$year}%"))
                ->with('filer:id,name')
                ->orderBy('due_on')->orderBy('form')
                ->get()
                ->map(fn (TaxFiling $f) => $this->present($f)),
        ]);
    }

    public function updateFiling(Request $request, TaxFiling $taxFiling): JsonResponse
    {
        Gate::authorize('manage-finances');
        $validated = $request->validate([
            'status' => ['required', Rule::in([TaxFiling::PENDING, TaxFiling::FILED, TaxFiling::NOT_APPLICABLE])],
            'filed_on' => ['required_if:status,filed', 'nullable', 'date', 'before_or_equal:today'],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'due_on' => ['nullable', 'date'],
        ]);

        $taxFiling->forceFill([
            'status' => $validated['status'],
            'filed_on' => $validated['status'] === TaxFiling::FILED ? $validated['filed_on'] : null,
            'filed_by' => $validated['status'] === TaxFiling::FILED ? $request->user()->id : null,
            'reference' => $validated['reference'] ?? $taxFiling->reference,
            'notes' => $validated['notes'] ?? $taxFiling->notes,
            'due_on' => $validated['due_on'] ?? $taxFiling->due_on,
        ])->save();

        return response()->json($this->present($taxFiling->load('filer:id,name')));
    }

    /** @return array{0: int, 1: int} */
    private function period(Request $request): array
    {
        $validated = $request->validate([
            'year' => ['nullable', 'integer', 'min:2020', 'max:2100'],
            'quarter' => ['nullable', 'integer', 'min:1', 'max:4'],
        ]);

        return [(int) ($validated['year'] ?? now()->year), (int) ($validated['quarter'] ?? now()->quarter)];
    }

    private function present(TaxFiling $f): array
    {
        return [
            'id' => $f->id,
            'form' => $f->form,
            'description' => BirCalendar::FORMS[$f->form] ?? $f->form,
            'period' => $f->period,
            'due_on' => $f->due_on->toDateString(),
            'status' => $f->status,
            'is_overdue' => $f->status === TaxFiling::PENDING && $f->due_on->lt(today()),
            'filed_on' => $f->filed_on?->toDateString(),
            'reference' => $f->reference,
            'notes' => $f->notes,
            'filed_by' => $f->filer?->name,
        ];
    }

    private function safe(string $value): string
    {
        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }
}
