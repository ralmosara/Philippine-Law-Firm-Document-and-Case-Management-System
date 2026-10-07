<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Billing\Models\PaymentProof;
use App\Domain\Billing\PaymentProofs;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** Finance's queue of proofs of payment uploaded by clients. */
class PaymentProofController extends Controller
{
    public function __construct(private readonly PaymentProofs $proofs) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('manage-finances');
        $validated = $request->validate(['status' => ['nullable', Rule::in(['pending', 'confirmed', 'rejected'])]]);

        $proofs = PaymentProof::query()
            ->where('status', $validated['status'] ?? 'pending')
            ->with(['invoice:id,number,total_cents,settled_cents,written_off_cents,status', 'client:id,name', 'file:id,original_name,mime_type', 'reviewer:id,name'])
            ->latest('id')
            ->limit(200)
            ->get();

        return response()->json(['data' => $proofs->map(fn (PaymentProof $p) => self::payload($p))]);
    }

    public function confirm(Request $request, PaymentProof $paymentProof): JsonResponse
    {
        Gate::authorize('manage-finances');
        $validated = $request->validate([
            'amount_cents' => ['nullable', 'integer', 'min:0', 'max:2000000000'],
            'withholding_cents' => ['nullable', 'integer', 'min:0', 'max:2000000000'],
            'received_on' => ['nullable', 'date', 'before_or_equal:today'],
        ]);

        $proof = $this->proofs->confirm($paymentProof, $request->user(), $validated['amount_cents'] ?? null, $validated['withholding_cents'] ?? 0, $validated['received_on'] ?? null);

        return response()->json(self::payload($proof->load(['invoice', 'client:id,name', 'file:id,original_name,mime_type', 'reviewer:id,name'])));
    }

    public function reject(Request $request, PaymentProof $paymentProof): JsonResponse
    {
        Gate::authorize('manage-finances');
        $validated = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        $proof = $this->proofs->reject($paymentProof, $validated['reason'], $request->user());

        return response()->json(self::payload($proof->load(['invoice', 'client:id,name', 'file:id,original_name,mime_type', 'reviewer:id,name'])));
    }

    public static function payload(PaymentProof $p, bool $forClient = false): array
    {
        return [
            'id' => $p->id,
            'status' => $p->status,
            'amount_cents' => $p->amount_cents,
            'paid_on' => $p->paid_on->toDateString(),
            'method' => $p->method,
            'method_label' => PaymentProof::METHODS[$p->method] ?? $p->method,
            'reference' => $p->reference,
            'note' => $p->note,
            'reject_reason' => $p->reject_reason,
            'reviewed_at' => $p->reviewed_at?->toIso8601String(),
            'created_at' => $p->created_at?->toIso8601String(),
            ...$forClient ? [] : [
                'invoice' => $p->invoice ? ['id' => $p->invoice->id, 'number' => $p->invoice->number, 'balance_cents' => $p->invoice->balanceDue(), 'status' => $p->invoice->status->value] : null,
                'client' => $p->client?->name,
                'file' => $p->file ? ['id' => $p->file->id, 'name' => $p->file->original_name, 'mime_type' => $p->file->mime_type] : null,
                'reviewed_by' => $p->reviewer?->name,
            ],
        ];
    }
}
