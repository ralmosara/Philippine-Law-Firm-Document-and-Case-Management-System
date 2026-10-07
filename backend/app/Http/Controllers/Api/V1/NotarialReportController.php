<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Documents\Services\NotarialReports;
use App\Domain\Matters\Models\Firm;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Pdf\PdfRenderer;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/** Each notary's monthly report to the clerk of court: download and mark submitted. */
class NotarialReportController extends Controller
{
    public function __construct(private readonly NotarialReports $reports) {}

    /** The last 12 months for one notary (yourself, or anyone for a managing partner). */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('practice-law');
        $notary = $this->notary($request);
        $months = collect(range(1, 12))->map(fn ($i) => CarbonImmutable::today()->subMonthsNoOverflow($i)->startOfMonth());

        return response()->json([
            'notary' => ['id' => $notary->id, 'name' => $notary->name, 'commission_number' => $notary->notarial_commission_number, 'commission_place' => $notary->notarial_commission_place, 'commission_expires_on' => $notary->notarial_commission_expires_on?->toDateString()],
            'notaries' => $request->user()->can('manage-firm') ? $this->reports->notaries()->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])->values() : [],
            'due_day' => NotarialReports::DUE_DAY,
            'months' => $months->map(function (CarbonImmutable $m) use ($notary) {
                $r = $this->reports->report($notary, $m);

                return ['month' => $m->format('Y-m'), 'entries' => $r->entries, 'submitted_at' => $r->submitted_at?->toIso8601String(), 'submitted_by' => $r->submitter?->name, 'notes' => $r->notes, 'id' => $r->id];
            })->values(),
        ]);
    }

    public function pdf(Request $request, string $month, PdfRenderer $pdf): Response
    {
        Gate::authorize('practice-law');
        $notary = $this->notary($request);
        $m = $this->month($month);

        return $pdf->download('pdf.notarial-report', [
            'notary' => $notary,
            'firm' => Firm::findOrFail($notary->firm_id),
            'month' => $m,
            'entries' => $this->reports->entries($notary, $m),
        ], "notarial-report-{$notary->name}-{$m->format('Y-m')}", 'a4-landscape');
    }

    public function submit(Request $request, string $month): JsonResponse
    {
        Gate::authorize('practice-law');
        $validated = $request->validate(['notes' => ['nullable', 'string', 'max:500']]);
        $notary = $this->notary($request);
        $report = $this->reports->markSubmitted($this->reports->report($notary, $this->month($month)), $validated['notes'] ?? null, $request->user());

        return response()->json(['submitted_at' => $report->submitted_at->toIso8601String()]);
    }

    /** A notary acts for themselves; a managing partner may act for any notary of the firm. */
    private function notary(Request $request): User
    {
        $id = $request->integer('notary_id') ?: $request->user()->id;
        if ($id !== $request->user()->id) {
            Gate::authorize('manage-firm');
        }

        return User::findOrFail($id);
    }

    private function month(string $month): CarbonImmutable
    {
        abort_unless(preg_match('/^\d{4}-\d{2}$/', $month) === 1, 404);
        $m = CarbonImmutable::createFromFormat('!Y-m', $month);
        abort_if($m->gte(CarbonImmutable::today()->startOfMonth()), 422, 'A month can be reported once it has ended.');

        return $m;
    }
}
