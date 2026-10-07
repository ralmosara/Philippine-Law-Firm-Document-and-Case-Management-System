<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Services\BillingRun;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Month-end billing: what is unbilled, draft many bills, issue many drafts. */
class BillingRunController extends Controller
{
    public function __construct(private readonly BillingRun $run) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('manage-finances');
        $validated = $request->validate(['through' => ['nullable', 'date']]);

        return response()->json([
            'matters' => $this->run->candidates($validated['through'] ?? null),
            'drafts' => Invoice::query()->where('status', InvoiceStatus::Draft->value)->with(['client:id,name,email', 'matter:id,reference,title'])->latest('id')->limit(300)->get()
                ->map(fn (Invoice $i) => [
                    'id' => $i->id,
                    'number' => $i->number,
                    'client' => $i->client?->name,
                    'client_has_email' => filled($i->client?->email),
                    'matter' => $i->matter ? "{$i->matter->reference} {$i->matter->title}" : null,
                    'total_cents' => $i->total_cents,
                    'created_at' => $i->created_at?->toIso8601String(),
                ]),
        ]);
    }

    public function draft(Request $request): JsonResponse
    {
        Gate::authorize('manage-finances');
        $validated = $request->validate([
            'matter_ids' => ['required', 'array', 'min:1', 'max:300'],
            'matter_ids.*' => ['integer'],
            'due_in_days' => ['required', 'integer', 'min:0', 'max:365'],
            'through' => ['nullable', 'date'],
        ]);

        return response()->json(['results' => $this->run->draft($validated['matter_ids'], $request->user(), $validated['due_in_days'], $validated['through'] ?? null)]);
    }

    public function issue(Request $request): JsonResponse
    {
        Gate::authorize('manage-finances');
        $validated = $request->validate([
            'invoice_ids' => ['required', 'array', 'min:1', 'max:300'],
            'invoice_ids.*' => ['integer'],
            'email' => ['boolean'],
        ]);

        return response()->json(['results' => $this->run->issue($validated['invoice_ids'], $request->user(), (bool) ($validated['email'] ?? false))]);
    }
}
