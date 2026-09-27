<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\InvoicePayment;
use App\Domain\Billing\Services\InvoicePayments;
use App\Domain\Documents\Actions\StoreMatterFile;
use App\Domain\Matters\Models\Matter;
use App\Domain\Trust\Models\TrustAccount;
use App\Http\Controllers\Controller;
use App\Http\Resources\InvoicePaymentResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Payments recorded against invoices (full or partial), the tax clients
 * withhold, and the BIR Form 2307 certificates the firm is still waiting for.
 */
class InvoicePaymentController extends Controller
{
    public function __construct(private readonly InvoicePayments $payments) {}

    public function index(Invoice $invoice): AnonymousResourceCollection
    {
        Gate::authorize('practice-law');

        return InvoicePaymentResource::collection($invoice->invoicePayments()->with('recorder:id,name')->get());
    }

    public function store(Request $request, Invoice $invoice): JsonResponse
    {
        Gate::authorize('manage-finances');

        $validated = $request->validate([
            'received_on' => ['required', 'date', 'before_or_equal:today'],
            'method' => ['required', Rule::in(array_diff(InvoicePayment::METHODS, ['online', 'trust']))],
            'amount_cents' => ['required', 'integer', 'min:0', 'max:2000000000'],
            'withholding_cents' => ['nullable', 'integer', 'min:0', 'max:2000000000'],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'trust_account_id' => ['nullable', 'integer'],
            'form_2307' => ['nullable', StoreMatterFile::rule()],
        ]);

        $trust = isset($validated['trust_account_id']) ? TrustAccount::findOrFail($validated['trust_account_id']) : null;

        unset($validated['form_2307']);
        $payment = $this->payments->record($invoice, $validated, $request->user(), $trust);

        if ($request->hasFile('form_2307') && $payment->withholding_cents > 0) {
            $this->payments->mark2307Received($payment, $this->store2307($request, $invoice));
        }

        return (new InvoicePaymentResource($payment->load('recorder:id,name')))->response()->setStatusCode(201);
    }

    public function void(Request $request, InvoicePayment $invoicePayment): InvoicePaymentResource
    {
        Gate::authorize('manage-finances');

        $validated = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        return new InvoicePaymentResource($this->payments->void($invoicePayment, $validated['reason'], $request->user()));
    }

    /** Record that the client's Form 2307 arrived, optionally attaching the scan. */
    public function receive2307(Request $request, InvoicePayment $invoicePayment): InvoicePaymentResource
    {
        Gate::authorize('manage-finances');

        $request->validate(['form_2307' => ['nullable', StoreMatterFile::rule()]]);

        $fileId = $request->hasFile('form_2307')
            ? $this->store2307($request, Invoice::findOrFail($invoicePayment->invoice_id))
            : null;

        return new InvoicePaymentResource($this->payments->mark2307Received($invoicePayment, $fileId));
    }

    /** Tax withheld by clients whose Form 2307 has not come in yet, oldest first. */
    public function awaiting2307(): AnonymousResourceCollection
    {
        Gate::authorize('manage-finances');

        return InvoicePaymentResource::collection(
            InvoicePayment::awaiting2307()
                ->with('invoice:id,number,client_id,matter_id', 'invoice.client:id,name')
                ->orderBy('received_on')
                ->orderBy('id')
                ->get()
        );
    }

    /** The certificate is kept with the matter's files, where the firm keeps everything else. */
    private function store2307(Request $request, Invoice $invoice): int
    {
        $matter = Matter::findOrFail($invoice->matter_id);

        return app(StoreMatterFile::class)
            ->execute($matter, $request->file('form_2307'), $request->user(), "BIR Form 2307 for invoice {$invoice->number}")
            ->id;
    }
}
