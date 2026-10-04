<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Billing\Models\Invoice;
use App\Domain\EInvoicing\EInvoice;
use App\Domain\EInvoicing\EInvoicing;
use App\Domain\Matters\Models\Firm;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Electronic invoicing: the firm's setting, the log of e-invoices, and sending them again. */
class EInvoiceController extends Controller
{
    public function __construct(private readonly EInvoicing $eInvoicing) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('manage-finances');
        $validated = $request->validate(['status' => ['nullable', Rule::in([EInvoice::PENDING, EInvoice::RECORDED, EInvoice::SUBMITTED, EInvoice::ACCEPTED, EInvoice::REJECTED, EInvoice::FAILED, EInvoicing::WITHDRAWN])]]);
        $firm = Firm::findOrFail($request->user()->firm_id);

        $page = EInvoice::query()
            ->with('invoice:id,number,total_cents,client_id', 'invoice.client:id,name')
            ->when($validated['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->latest('id')
            ->paginate(25);

        return response()->json([
            'settings' => $this->settings($firm),
            'counts' => EInvoice::query()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
            'data' => collect($page->items())->map(fn (EInvoice $e) => $this->row($e)),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        Gate::authorize('manage-firm');
        $validated = $request->validate([
            'einvoicing_enabled' => ['required', 'boolean'],
            'tin_branch_code' => ['required', 'digits_between:3,5'],
        ], ['tin_branch_code.digits_between' => 'The branch code is the 3 to 5 digits after your TIN (00000 for the head office).']);

        $firm = Firm::findOrFail($request->user()->firm_id);
        if ($validated['einvoicing_enabled'] && strlen((string) preg_replace('/\D/', '', (string) $firm->tin)) < 9) {
            throw ValidationException::withMessages(['einvoicing_enabled' => 'Add the firm\'s TIN in the firm settings first.']);
        }
        $firm->fill(['einvoicing_enabled' => $validated['einvoicing_enabled'], 'tin_branch_code' => str_pad($validated['tin_branch_code'], 5, '0', STR_PAD_LEFT)])->save();

        return response()->json(['settings' => $this->settings($firm)]);
    }

    /** The e-invoice (and any cancellation) of one invoice. */
    public function forInvoice(Invoice $invoice): JsonResponse
    {
        Gate::authorize('manage-finances');
        $firm = Firm::findOrFail($invoice->firm_id);

        return response()->json([
            'enabled' => $this->eInvoicing->enabled($firm),
            'data' => EInvoice::query()->with('invoice:id,number,total_cents,client_id', 'invoice.client:id,name')->where('invoice_id', $invoice->id)->orderBy('id')->get()->map(fn (EInvoice $e) => $this->row($e)),
        ]);
    }

    /** The e-invoice document exactly as sent (or kept), for checking or a provider's support. */
    public function payload(EInvoice $eInvoice): JsonResponse
    {
        Gate::authorize('manage-finances');
        $eInvoice->loadMissing('invoice:id,number');

        return response()->json($eInvoice->payload, 200, [
            'Content-Disposition' => 'attachment; filename="e-invoice-'.preg_replace('/[^A-Za-z0-9-]/', '', (string) $eInvoice->invoice?->number)."-{$eInvoice->kind}.json\"",
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function retry(EInvoice $eInvoice): JsonResponse
    {
        Gate::authorize('manage-finances');
        if (! $eInvoice->canRetry()) {
            throw ValidationException::withMessages(['status' => 'Only a rejected or failed e-invoice can be sent again.']);
        }

        return response()->json($this->row($this->eInvoicing->retry($eInvoice)->load('invoice:id,number,total_cents,client_id', 'invoice.client:id,name')));
    }

    private function settings(Firm $firm): array
    {
        return [
            'einvoicing_enabled' => (bool) $firm->einvoicing_enabled,
            'tin' => $firm->tin,
            'tin_branch_code' => $firm->tin_branch_code ?: '00000',
            'deadline_days' => (int) config('services.einvoicing.deadline_days', 3),
            ...$this->eInvoicing->driverStatus(),
        ];
    }

    private function row(EInvoice $e): array
    {
        return [
            'id' => $e->id,
            'kind' => $e->kind,
            'status' => $e->status,
            'driver' => $e->driver,
            'invoice' => $e->invoice ? ['id' => $e->invoice->id, 'number' => $e->invoice->number, 'total_cents' => $e->invoice->total_cents, 'client' => $e->invoice->client?->name] : null,
            'provider_reference' => $e->provider_reference,
            'error' => $e->error,
            'attempts' => $e->attempts,
            'payload_sha256' => $e->payload_sha256,
            'due_on' => $e->due_on?->toDateString(),
            'overdue' => ! in_array($e->status, [...EInvoice::SETTLED, EInvoice::SUBMITTED, EInvoicing::WITHDRAWN], true) && $e->due_on?->lt(today()),
            'submitted_at' => $e->submitted_at?->toIso8601String(),
            'accepted_at' => $e->accepted_at?->toIso8601String(),
            'created_at' => $e->created_at?->toIso8601String(),
            'can_retry' => $e->canRetry(),
        ];
    }
}
