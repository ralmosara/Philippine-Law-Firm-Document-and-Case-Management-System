<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Billing\Enums\ExpenseCategory;
use App\Domain\Billing\Models\DisbursementRequest;
use App\Domain\Billing\Models\Expense;
use App\Domain\Billing\Services\Disbursements;
use App\Domain\Matters\Models\Matter;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/** Cash advances for case costs: request, approval, release and liquidation. */
class DisbursementController extends Controller
{
    public function __construct(private readonly Disbursements $disbursements) {}

    /**
     * For a matter, all of its requests. Otherwise finance partners see the
     * firm's, and everyone else their own.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'matter_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(['open', 'pending', 'approved', 'released', 'overdue', 'liquidated', 'closed', 'all'])],
        ]);
        $finance = $request->user()->role->canManageFinances();

        $rows = DisbursementRequest::query()
            ->with(['matter:id,reference,title', 'requester:id,name', 'decider:id,name', 'releaser:id,name', 'trustAccount:id,account_number,balance_cents', 'expenses.receipt:id,original_name'])
            ->when($validated['matter_id'] ?? null, fn ($q, $id) => $q->where('matter_id', $id), fn ($q) => $finance ? $q : $q->where('requested_by', $request->user()->id))
            ->when($validated['status'] ?? 'open', fn ($q, $status) => match ($status) {
                'open' => $q->whereIn('status', [DisbursementRequest::PENDING, DisbursementRequest::APPROVED, DisbursementRequest::RELEASED]),
                'overdue' => $q->where('status', DisbursementRequest::RELEASED)->whereDate('liquidation_due_on', '<', today()->toDateString()),
                'closed' => $q->whereIn('status', [DisbursementRequest::LIQUIDATED, DisbursementRequest::REJECTED, DisbursementRequest::CANCELLED]),
                'all' => $q,
                default => $q->where('status', $status),
            })
            ->latest('id')->limit(300)->get();

        $scope = DisbursementRequest::query()->when(! $finance, fn ($q) => $q->where('requested_by', $request->user()->id));

        return response()->json([
            'data' => $rows->map(fn (DisbursementRequest $r) => $this->present($r, $request)),
            'counts' => [
                'pending' => (clone $scope)->where('status', DisbursementRequest::PENDING)->count(),
                'approved' => (clone $scope)->where('status', DisbursementRequest::APPROVED)->count(),
                'released' => (clone $scope)->where('status', DisbursementRequest::RELEASED)->count(),
                'overdue' => (clone $scope)->where('status', DisbursementRequest::RELEASED)->whereDate('liquidation_due_on', '<', today()->toDateString())->count(),
                'outstanding_cents' => (int) (clone $scope)->where('status', DisbursementRequest::RELEASED)->sum('amount_cents'),
            ],
            'categories' => collect(ExpenseCategory::cases())->map(fn ($c) => ['value' => $c->value, 'label' => $c->label()]),
        ]);
    }

    public function store(Request $request, Matter $matter): JsonResponse
    {
        Gate::authorize('work-matters');
        $validated = $request->validate([
            'category' => ['required', new Enum(ExpenseCategory::class)],
            'description' => ['required', 'string', 'max:500'],
            'amount_cents' => ['required', 'integer', 'min:100', 'max:2000000000'],
            'needed_by' => ['nullable', 'date', 'after_or_equal:today'],
            'source' => ['required', Rule::in([DisbursementRequest::FROM_FIRM, DisbursementRequest::FROM_TRUST])],
            'trust_account_id' => ['nullable', 'integer'],
        ]);

        $disbursement = $this->disbursements->request($matter, $request->user(), $validated);

        return response()->json($this->present($disbursement->refresh(), $request), 201);
    }

    public function approve(Request $request, DisbursementRequest $disbursement): JsonResponse
    {
        Gate::authorize('manage-finances');
        $this->disbursements->approve($disbursement, $request->user(), $request->validate(['note' => ['nullable', 'string', 'max:500']])['note'] ?? null);

        return $this->show($request, $disbursement);
    }

    public function reject(Request $request, DisbursementRequest $disbursement): JsonResponse
    {
        Gate::authorize('manage-finances');
        $this->disbursements->reject($disbursement, $request->user(), $request->validate(['reason' => ['required', 'string', 'max:500']])['reason']);

        return $this->show($request, $disbursement);
    }

    public function release(Request $request, DisbursementRequest $disbursement): JsonResponse
    {
        Gate::authorize('manage-finances');
        $this->disbursements->release($disbursement, $request->user(), $request->validate(['reference' => ['nullable', 'string', 'max:64']])['reference'] ?? null);

        return $this->show($request, $disbursement);
    }

    public function cancel(Request $request, DisbursementRequest $disbursement): JsonResponse
    {
        abort_unless($disbursement->requested_by === $request->user()->id || $request->user()->role->canManageFinances(), 403);
        $this->disbursements->cancel($disbursement);

        return $this->show($request, $disbursement);
    }

    public function liquidate(Request $request, DisbursementRequest $disbursement): JsonResponse
    {
        abort_unless($disbursement->requested_by === $request->user()->id || $request->user()->role->canManageFinances(), 403);
        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.expense_date' => ['required', 'date', 'before_or_equal:today'],
            'items.*.category' => ['required', new Enum(ExpenseCategory::class)],
            'items.*.description' => ['required', 'string', 'max:450'],
            'items.*.amount_cents' => ['required', 'integer', 'min:1', 'max:2000000000'],
            'items.*.receipt_file_id' => ['nullable', 'integer'],
            'items.*.is_billable' => ['nullable', 'boolean'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $this->disbursements->liquidate($disbursement, $request->user(), $validated['items'], $validated['note'] ?? null);

        return $this->show($request, $disbursement);
    }

    private function show(Request $request, DisbursementRequest $disbursement): JsonResponse
    {
        return response()->json($this->present($disbursement->refresh()->load(['matter:id,reference,title', 'requester:id,name', 'decider:id,name', 'releaser:id,name', 'trustAccount:id,account_number,balance_cents', 'expenses.receipt:id,original_name']), $request));
    }

    private function present(DisbursementRequest $r, Request $request): array
    {
        $user = $request->user();
        $finance = $user->role->canManageFinances();
        $mine = $r->requested_by === $user->id;

        return [
            'id' => $r->id,
            'matter' => $r->matter ? ['id' => $r->matter->id, 'reference' => $r->matter->reference, 'title' => $r->matter->title] : null,
            'requester' => $r->requester?->name,
            'category' => $r->category->value,
            'category_label' => $r->category->label(),
            'description' => $r->description,
            'amount_cents' => $r->amount_cents,
            'needed_by' => $r->needed_by?->toDateString(),
            'source' => $r->source,
            'trust_account' => $r->trustAccount ? ['id' => $r->trustAccount->id, 'account_number' => $r->trustAccount->account_number, 'balance_cents' => $r->trustAccount->balance_cents] : null,
            'status' => $r->status,
            'decided_by' => $r->decider?->name,
            'decided_at' => $r->decided_at?->toIso8601String(),
            'decision_note' => $r->decision_note,
            'released_by' => $r->releaser?->name,
            'released_at' => $r->released_at?->toIso8601String(),
            'release_reference' => $r->release_reference,
            'liquidation_due_on' => $r->liquidation_due_on?->toDateString(),
            'is_overdue' => $r->isOverdue(),
            'spent_cents' => $r->spent_cents,
            'returned_cents' => $r->returned_cents,
            // Spent beyond the advance, to be reimbursed to the lawyer (firm funds only).
            'reimburse_cents' => $r->spent_cents !== null ? max(0, $r->spent_cents - $r->amount_cents) : null,
            'liquidated_at' => $r->liquidated_at?->toIso8601String(),
            'liquidation_note' => $r->liquidation_note,
            'expenses' => $r->relationLoaded('expenses') ? $r->expenses->map(fn (Expense $e) => [
                'id' => $e->id, 'category' => $e->category->value, 'description' => $e->description, 'amount_cents' => $e->amount_cents,
                'expense_date' => $e->expense_date?->toDateString(), 'receipt' => $e->receipt ? ['id' => $e->receipt->id, 'name' => $e->receipt->original_name] : null,
            ]) : [],
            'can' => [
                'approve' => $finance && $r->status === DisbursementRequest::PENDING && (! $mine || $user->role === Role::ManagingPartner),
                'reject' => $finance && in_array($r->status, [DisbursementRequest::PENDING, DisbursementRequest::APPROVED], true),
                'release' => $finance && $r->status === DisbursementRequest::APPROVED,
                'cancel' => ($mine || $finance) && in_array($r->status, [DisbursementRequest::PENDING, DisbursementRequest::APPROVED], true),
                'liquidate' => ($mine || $finance) && $r->status === DisbursementRequest::RELEASED,
            ],
        ];
    }
}
