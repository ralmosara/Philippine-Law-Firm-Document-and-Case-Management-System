<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\InvoiceLine;
use App\Domain\Billing\Services\InvoiceAdjustments;
use App\Http\Controllers\Controller;
use App\Http\Resources\InvoiceResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Write-downs and discounts on drafts, write-offs on issued bills. */
class InvoiceAdjustmentController extends Controller
{
    public function __construct(private readonly InvoiceAdjustments $adjustments) {}

    public function writeDown(Request $request, Invoice $invoice, int $line): InvoiceResource
    {
        Gate::authorize('manage-finances');
        $validated = $request->validate([
            'amount_cents' => ['required', 'integer', 'min:0', 'max:2000000000'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);
        $model = InvoiceLine::where('invoice_id', $invoice->id)->findOrFail($line);

        return $this->resource($this->adjustments->writeDown($model, $validated['amount_cents'], $validated['reason'] ?? null, $request->user()));
    }

    public function discount(Request $request, Invoice $invoice): InvoiceResource
    {
        Gate::authorize('manage-finances');
        $validated = $request->validate([
            'discount_cents' => ['required', 'integer', 'min:0', 'max:2000000000'],
            'discount_reason' => ['nullable', 'string', 'max:255'],
        ]);

        return $this->resource($this->adjustments->discount($invoice, $validated['discount_cents'], $validated['discount_reason'] ?? null, $request->user()));
    }

    public function writeOff(Request $request, Invoice $invoice): InvoiceResource
    {
        Gate::authorize('manage-finances');
        $validated = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return $this->resource($this->adjustments->writeOff($invoice, $validated['reason'], $request->user()));
    }

    public function undoWriteOff(Request $request, Invoice $invoice): InvoiceResource
    {
        Gate::authorize('manage-finances');

        return $this->resource($this->adjustments->undoWriteOff($invoice, $request->user()));
    }

    private function resource(Invoice $invoice): InvoiceResource
    {
        return new InvoiceResource($invoice->load(['client', 'matter', 'lines', 'invoicePayments', 'writtenOffBy:id,name']));
    }
}
