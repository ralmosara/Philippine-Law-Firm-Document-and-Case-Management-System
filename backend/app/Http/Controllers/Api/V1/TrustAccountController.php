<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Matters\Models\Client;
use App\Domain\Matters\Models\Matter;
use App\Domain\Trust\Enums\TrustTransactionType;
use App\Domain\Trust\Models\TrustAccount;
use App\Domain\Trust\Services\TrustLedgerService;
use App\Http\Controllers\Controller;
use App\Http\Resources\TrustAccountResource;
use App\Http\Resources\TrustTransactionResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rules\Enum;
use LogicException;

class TrustAccountController extends Controller
{
    public function __construct(private readonly TrustLedgerService $ledger) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('work-matters');

        $accounts = TrustAccount::query()
            ->with(['client', 'matter'])
            ->when($request->query('client_id'), fn ($q, $id) => $q->where('client_id', $id))
            ->when($request->query('matter_id'), fn ($q, $id) => $q->where('matter_id', $id))
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->orderBy('account_number')
            ->paginate($this->perPage($request, 50));

        return TrustAccountResource::collection($accounts)->additional([
            'totals' => ['balance_cents' => (int) TrustAccount::sum('balance_cents')],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('manage-finances');

        $validated = $request->validate([
            'client_id' => ['required', 'integer'],
            'matter_id' => ['nullable', 'integer'],
        ]);

        $client = Client::findOrFail($validated['client_id']);
        $matter = isset($validated['matter_id']) ? Matter::findOrFail($validated['matter_id']) : null;

        if ($matter && $matter->client_id !== $client->id) {
            abort(422, 'The matter does not belong to this client.');
        }

        $account = TrustAccount::create([
            'firm_id' => $client->firm_id,
            'client_id' => $client->id,
            'matter_id' => $matter?->id,
        ]);

        return (new TrustAccountResource($account->refresh()->load(['client', 'matter'])))->response()->setStatusCode(201);
    }

    public function show(TrustAccount $trustAccount): TrustAccountResource
    {
        Gate::authorize('work-matters');

        return new TrustAccountResource($trustAccount->load(['client', 'matter']));
    }

    public function transactions(Request $request, TrustAccount $trustAccount): AnonymousResourceCollection
    {
        Gate::authorize('work-matters');

        return TrustTransactionResource::collection(
            $trustAccount->transactions()->with('creator')->paginate($this->perPage($request, 50))
        );
    }

    /**
     * Post a deposit (any staff, e.g. on receipt of a client's check) or a
     * disbursement (partners only, since it releases client money).
     */
    public function record(Request $request, TrustAccount $trustAccount): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', new Enum(TrustTransactionType::class)],
            'amount_cents' => ['required', 'integer', 'min:1', 'max:2000000000'],
            'description' => ['required', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:64'],
        ]);

        $type = TrustTransactionType::from($validated['type']);
        Gate::authorize($type === TrustTransactionType::Disbursement ? 'manage-finances' : 'work-matters');

        try {
            $transaction = $this->ledger->record($trustAccount, $type, $validated['amount_cents'], $validated['description'], $validated['reference'] ?? null, $request->user());
        } catch (LogicException $e) {
            abort(422, $e->getMessage());
        }

        return response()->json([
            'transaction' => new TrustTransactionResource($transaction->load('creator')),
            'account' => new TrustAccountResource($trustAccount->load(['client', 'matter'])),
        ], 201);
    }

    public function reconcile(TrustAccount $trustAccount): JsonResponse
    {
        Gate::authorize('manage-finances');

        $problems = $this->ledger->reconcile($trustAccount);

        return response()->json(['reconciled' => $problems === [], 'problems' => $problems]);
    }

    public function close(TrustAccount $trustAccount): TrustAccountResource
    {
        Gate::authorize('manage-finances');

        try {
            $this->ledger->close($trustAccount);
        } catch (LogicException $e) {
            abort(422, $e->getMessage());
        }

        return new TrustAccountResource($trustAccount->load(['client', 'matter']));
    }
}
