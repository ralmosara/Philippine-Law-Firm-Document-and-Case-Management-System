<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Matters\Models\Firm;
use App\Domain\Trust\Models\TrustReconciliation;
use App\Domain\Trust\Services\TrustReconciliations;
use App\Http\Controllers\Controller;
use App\Support\Pdf\PdfRenderer;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/** The monthly three-way reconciliation of trust funds with the bank. */
class TrustReconciliationController extends Controller
{
    public function __construct(private readonly TrustReconciliations $reconciliations) {}

    public function index(): JsonResponse
    {
        Gate::authorize('manage-finances');
        $last = CarbonImmutable::today()->subMonthNoOverflow()->endOfMonth()->startOfDay();

        return response()->json([
            'data' => TrustReconciliation::query()->with(['preparer:id,name', 'signer:id,name'])->latest('period_end')->limit(24)->get()->map(fn ($r) => $this->payload($r)),
            // The month to do next, with its ledger figures already worked out.
            'next' => ['period_end' => $last->toDateString(), ...collect($this->reconciliations->figures($last))->except('accounts')->all()],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('manage-finances');
        $validated = $request->validate([
            'period_end' => ['required', 'date'],
            'bank_account' => ['required', 'string', 'max:120'],
            'statement_balance_cents' => ['required', 'integer', 'min:-100000000000', 'max:100000000000'],
            'deposits_in_transit' => ['array', 'max:200'],
            'deposits_in_transit.*.description' => ['required', 'string', 'max:255'],
            'deposits_in_transit.*.amount_cents' => ['required', 'integer', 'min:1', 'max:100000000000'],
            'outstanding_checks' => ['array', 'max:200'],
            'outstanding_checks.*.description' => ['required', 'string', 'max:255'],
            'outstanding_checks.*.amount_cents' => ['required', 'integer', 'min:1', 'max:100000000000'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $reconciliation = $this->reconciliations->prepare(CarbonImmutable::parse($validated['period_end']), $validated, $request->user());

        return response()->json($this->payload($reconciliation->load(['preparer:id,name', 'signer:id,name'])), 201);
    }

    public function signOff(Request $request, TrustReconciliation $trustReconciliation): JsonResponse
    {
        Gate::authorize('manage-finances');
        $validated = $request->validate(['notes' => ['nullable', 'string', 'max:5000']]);

        $reconciliation = $this->reconciliations->signOff($trustReconciliation, $validated['notes'] ?? null, $request->user());

        return response()->json($this->payload($reconciliation->load(['preparer:id,name', 'signer:id,name'])));
    }

    public function pdf(TrustReconciliation $trustReconciliation, PdfRenderer $pdf): Response
    {
        Gate::authorize('manage-finances');
        $r = $trustReconciliation->load(['preparer:id,name', 'signer:id,name']);

        return $pdf->download('pdf.trust-reconciliation', [
            'r' => $r,
            'firm' => Firm::findOrFail($r->firm_id),
            'accounts' => $this->reconciliations->figures(CarbonImmutable::instance($r->period_end))['accounts'],
        ], 'trust-reconciliation-'.$r->period_end->format('Y-m'));
    }

    private function payload(TrustReconciliation $r): array
    {
        return [
            'id' => $r->id,
            'period_end' => $r->period_end->toDateString(),
            'bank_account' => $r->bank_account,
            'statement_balance_cents' => $r->statement_balance_cents,
            'deposits_in_transit' => $r->deposits_in_transit,
            'outstanding_checks' => $r->outstanding_checks,
            'adjusted_bank_cents' => $r->adjusted_bank_cents,
            'ledger_cents' => $r->ledger_cents,
            'client_total_cents' => $r->client_total_cents,
            'difference_cents' => $r->difference(),
            'balances' => $r->balances(),
            'exceptions' => $r->exceptions,
            'notes' => $r->notes,
            'prepared_by' => $r->preparer?->name,
            'signed_off_by' => $r->signer?->name,
            'signed_off_at' => $r->signed_off_at?->toIso8601String(),
            'updated_at' => $r->updated_at?->toIso8601String(),
        ];
    }
}
