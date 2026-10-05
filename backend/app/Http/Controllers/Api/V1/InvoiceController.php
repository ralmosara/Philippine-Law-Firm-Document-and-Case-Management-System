<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\Payments\OnlinePayments;
use App\Domain\Billing\Services\InvoiceGenerator;
use App\Domain\Matters\Models\Firm;
use App\Domain\Matters\Models\Matter;
use App\Domain\Trust\Models\TrustAccount;
use App\Http\Controllers\Controller;
use App\Http\Resources\InvoiceResource;
use App\Support\Pdf\PdfRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class InvoiceController extends Controller
{
    public function __construct(private readonly InvoiceGenerator $invoices) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('practice-law');

        $request->validate(['status' => ['nullable', new Enum(InvoiceStatus::class)]]);

        $invoices = Invoice::query()
            ->with(['client', 'matter'])
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->query('client_id'), fn ($q, $id) => $q->where('client_id', $id))
            ->when($request->query('matter_id'), fn ($q, $id) => $q->where('matter_id', $id))
            ->when($request->query('search'), fn ($q, $search) => $q->whereLike('number', "%{$search}%"))
            ->latest('id')
            ->paginate($this->perPage($request, 25));

        return InvoiceResource::collection($invoices);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('manage-finances');

        $validated = $request->validate([
            'matter_id' => ['required', 'integer'],
            'time_entry_ids' => ['nullable', 'array'],
            'time_entry_ids.*' => ['integer'],
            // Omitted: bill everything unbilled; []: bill none of that kind.
            'expense_ids' => ['nullable', 'array'],
            'expense_ids.*' => ['integer'],
            'fee_lines' => ['array', 'max:20'],
            'fee_lines.*.description' => ['required', 'string', 'max:500'],
            'fee_lines.*.amount_cents' => ['required', 'integer', 'min:1', 'max:2000000000'],
            'due_in_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $invoice = $this->invoices->generateForMatter(
            Matter::findOrFail($validated['matter_id']),
            $request->user(),
            $validated['time_entry_ids'] ?? null,
            $validated['due_in_days'] ?? 30,
            $validated['notes'] ?? null,
            $validated['expense_ids'] ?? null,
            array_map(fn (array $line) => ['description' => $line['description'], 'amount_cents' => (int) $line['amount_cents']], $validated['fee_lines'] ?? []),
        );

        return (new InvoiceResource($invoice->load(['client', 'matter', 'lines'])))->response()->setStatusCode(201);
    }

    public function show(Invoice $invoice): InvoiceResource
    {
        Gate::authorize('practice-law');

        return new InvoiceResource($invoice->load(['client', 'matter', 'lines', 'payments', 'invoicePayments.recorder:id,name', 'reminders.sender:id,name']));
    }

    public function pdf(Invoice $invoice, PdfRenderer $pdf): Response
    {
        Gate::authorize('practice-law');

        return $pdf->download('pdf.invoice', static::pdfData($invoice), "billing-statement-{$invoice->number}");
    }

    public static function pdfData(Invoice $invoice): array
    {
        return ['invoice' => $invoice->loadMissing(['client', 'matter', 'lines']), 'firm' => Firm::findOrFail($invoice->firm_id)];
    }

    public function issue(Invoice $invoice): InvoiceResource
    {
        Gate::authorize('manage-finances');

        return new InvoiceResource($this->invoices->issue($invoice)->load(['client', 'matter', 'lines']));
    }

    public function pay(Request $request, Invoice $invoice): InvoiceResource
    {
        Gate::authorize('manage-finances');

        $validated = $request->validate([
            'payment_reference' => ['nullable', 'string', 'max:64'],
            'trust_account_id' => ['nullable', 'integer'],
        ]);

        $trust = isset($validated['trust_account_id']) ? TrustAccount::findOrFail($validated['trust_account_id']) : null;

        $this->invoices->markPaid($invoice, $request->user(), $validated['payment_reference'] ?? null, $trust);

        return new InvoiceResource($invoice->load(['client', 'matter', 'lines']));
    }

    /**
     * A PayMongo checkout link the firm can send to the client (email, Viber)
     * for an issued invoice. The webhook marks the invoice paid.
     */
    /** Refund through PayMongo an online payment that could not be applied to its invoice. */
    public function refundOnlinePayment(Request $request, Payment $payment, OnlinePayments $payments): InvoiceResource
    {
        Gate::authorize('manage-finances');
        $validated = $request->validate([
            'reason' => ['required', Rule::in(['duplicate', 'requested_by_customer', 'fraudulent', 'others'])],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);
        $payments->refund($payment, $validated['reason'], $validated['notes'] ?? null, $request->user());
        $invoice = Invoice::findOrFail($payment->invoice_id);

        return new InvoiceResource($invoice->load(['client', 'matter', 'lines', 'payments', 'invoicePayments.recorder:id,name', 'reminders.sender:id,name']));
    }

    public function paymentLink(Invoice $invoice, OnlinePayments $payments): JsonResponse
    {
        Gate::authorize('manage-finances');

        $payment = $payments->startCheckout($invoice, rtrim(config('app.frontend_url'), '/').'/portal');

        return response()->json(['checkout_url' => $payment->checkout_url], 201);
    }

    public function void(Invoice $invoice): InvoiceResource
    {
        Gate::authorize('manage-finances');

        return new InvoiceResource($this->invoices->void($invoice)->load(['client', 'matter', 'lines']));
    }
}
