<?php

namespace App\Domain\Billing\Services;

use App\Domain\Billing\Models\DisbursementRequest;
use App\Domain\Billing\Models\Expense;
use App\Domain\Billing\Notifications\DisbursementNotice;
use App\Domain\Deadlines\Services\DeadlineCalculator;
use App\Domain\Documents\Models\MatterFile;
use App\Domain\Matters\Models\Matter;
use App\Domain\Trust\Exceptions\InsufficientTrustFunds;
use App\Domain\Trust\Models\TrustAccount;
use App\Domain\Trust\Services\TrustLedgerService;
use App\Enums\Role;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Cash advances for case costs. The lawyer asks for the money, a partner
 * approves it (never the person who asked, unless a managing partner) and
 * releases it, from firm funds or the client's trust deposit, and the lawyer
 * liquidates it with receipts within a week.
 *
 * Liquidation turns each receipt into an expense on the matter. Costs paid
 * from firm funds are billed to the client at cost; costs paid from the
 * client's trust deposit are already paid, so they are recorded but not
 * billed again, and any unspent balance goes back to the trust account.
 */
class Disbursements
{
    public function __construct(
        private readonly TrustLedgerService $ledger,
        private readonly DeadlineCalculator $calculator,
        private readonly TenantContext $tenant,
    ) {}

    public function request(Matter $matter, User $by, array $data): DisbursementRequest
    {
        if (($data['source'] ?? DisbursementRequest::FROM_FIRM) === DisbursementRequest::FROM_TRUST) {
            $account = TrustAccount::find($data['trust_account_id'] ?? null);
            if (! $account || (int) $account->client_id !== (int) $matter->client_id || ($account->matter_id && (int) $account->matter_id !== $matter->id) || ! $account->isOpen()) {
                throw ValidationException::withMessages(['trust_account_id' => "Choose an open trust account of this matter's client."]);
            }
        } else {
            $data['trust_account_id'] = null;
        }

        $request = DisbursementRequest::create([...$data, 'firm_id' => $matter->firm_id, 'matter_id' => $matter->id, 'requested_by' => $by->id]);

        foreach ($this->financeUsers($matter->firm_id)->reject(fn (User $u) => $u->id === $by->id) as $user) {
            $user->notify(new DisbursementNotice($request, 'requested'));
        }

        return $request;
    }

    public function approve(DisbursementRequest $request, User $by, ?string $note = null): void
    {
        $this->expect($request, [DisbursementRequest::PENDING]);
        if ($request->requested_by === $by->id && $by->role !== Role::ManagingPartner) {
            abort(403, 'Someone other than the person who asked must approve this request.');
        }
        $this->decide($request, DisbursementRequest::APPROVED, $by, $note);
    }

    public function reject(DisbursementRequest $request, User $by, string $reason): void
    {
        $this->expect($request, [DisbursementRequest::PENDING, DisbursementRequest::APPROVED]);
        $this->decide($request, DisbursementRequest::REJECTED, $by, $reason);
    }

    public function cancel(DisbursementRequest $request): void
    {
        $this->expect($request, [DisbursementRequest::PENDING, DisbursementRequest::APPROVED]);
        $request->forceFill(['status' => DisbursementRequest::CANCELLED])->save();
    }

    /** Hand over the money. From trust, this is a trust disbursement in the client's ledger. */
    public function release(DisbursementRequest $request, User $by, ?string $reference = null): void
    {
        $this->expect($request, [DisbursementRequest::APPROVED]);

        DB::transaction(function () use ($request, $by, $reference) {
            if ($request->source === DisbursementRequest::FROM_TRUST) {
                $this->trust(fn () => $this->ledger->disburse(
                    TrustAccount::findOrFail($request->trust_account_id),
                    $request->amount_cents,
                    "Cash advance for {$request->category->label()}: {$request->description}",
                    $reference ?: "DR-{$request->id}",
                    $by,
                ));
            }

            $request->forceFill([
                'status' => DisbursementRequest::RELEASED,
                'released_by' => $by->id,
                'released_at' => now(),
                'release_reference' => $reference,
                'liquidation_due_on' => $this->workingDay(CarbonImmutable::today()->addDays(DisbursementRequest::LIQUIDATE_WITHIN_DAYS))->toDateString(),
            ])->save();
        });

        $request->requester?->notify(new DisbursementNotice($request, 'released'));
    }

    /**
     * Account for the advance with what was spent, one line per receipt.
     *
     * @param  list<array{expense_date: string, category: string, description: string, amount_cents: int, receipt_file_id?: int|null, is_billable?: bool}>  $items
     */
    public function liquidate(DisbursementRequest $request, User $by, array $items, ?string $note = null): void
    {
        $this->expect($request, [DisbursementRequest::RELEASED]);
        $spent = array_sum(array_column($items, 'amount_cents'));
        $fromTrust = $request->source === DisbursementRequest::FROM_TRUST;

        if ($fromTrust && $spent > $request->amount_cents) {
            throw ValidationException::withMessages(['items' => 'More was spent than was released from trust. Record the excess as a separate expense so it is billed.']);
        }
        foreach ($items as $i => $item) {
            if (! empty($item['receipt_file_id']) && ! MatterFile::whereKey($item['receipt_file_id'])->where('matter_id', $request->matter_id)->exists()) {
                throw ValidationException::withMessages(["items.{$i}.receipt_file_id" => 'Choose a receipt uploaded to this matter.']);
            }
        }

        DB::transaction(function () use ($request, $by, $items, $note, $spent, $fromTrust) {
            foreach ($items as $item) {
                Expense::create([
                    'firm_id' => $request->firm_id,
                    'matter_id' => $request->matter_id,
                    'user_id' => $request->requested_by,
                    'expense_date' => $item['expense_date'],
                    'category' => $item['category'],
                    'description' => $item['description'].($fromTrust ? ' (paid from trust deposit)' : ''),
                    'amount_cents' => $item['amount_cents'],
                    // Paid with the client's own deposit: recorded, not billed again.
                    'is_billable' => $fromTrust ? false : ($item['is_billable'] ?? true),
                    'receipt_file_id' => $item['receipt_file_id'] ?? null,
                    'disbursement_request_id' => $request->id,
                ]);
            }

            $unspent = max(0, $request->amount_cents - $spent);
            if ($fromTrust && $unspent > 0) {
                $this->trust(fn () => $this->ledger->deposit(
                    TrustAccount::findOrFail($request->trust_account_id),
                    $unspent,
                    "Unspent cash advance returned: {$request->description}",
                    "DR-{$request->id}",
                    $by,
                ));
            }

            $request->forceFill([
                'status' => DisbursementRequest::LIQUIDATED,
                'spent_cents' => $spent,
                'returned_cents' => $unspent,
                'liquidated_by' => $by->id,
                'liquidated_at' => now(),
                'liquidation_note' => $note,
            ])->save();
        });
    }

    /**
     * The requester is reminded on the day liquidation is due; once overdue,
     * the requester and the finance partners are told.
     */
    public function sendReminders(?CarbonImmutable $today = null): int
    {
        $today ??= CarbonImmutable::today();
        $sent = 0;

        DisbursementRequest::withoutGlobalScopes()
            ->where('status', DisbursementRequest::RELEASED)
            ->whereDate('liquidation_due_on', '<=', $today->toDateString())
            ->each(function (DisbursementRequest $request) use ($today, &$sent) {
                $stage = $request->liquidation_due_on->lt($today) ? 'overdue' : 'due';
                if ($request->last_reminder === $stage || $request->last_reminder === 'overdue') {
                    return;
                }
                $claimed = DB::table('disbursement_requests')->where('id', $request->id)
                    ->where(fn ($q) => $q->whereNull('last_reminder')->orWhere('last_reminder', '!=', $stage))
                    ->update(['last_reminder' => $stage]);
                if ($claimed !== 1) {
                    return;
                }

                $this->tenant->runAs($request->firm_id, function () use ($request, $stage, &$sent) {
                    $request = DisbursementRequest::with(['matter:id,reference,title', 'requester'])->find($request->id);
                    $recipients = collect([$request->requester])->filter();
                    if ($stage === 'overdue') {
                        $recipients = $recipients->merge($this->financeUsers($request->firm_id))->unique('id');
                    }
                    foreach ($recipients as $user) {
                        $user->notify(new DisbursementNotice($request, $stage === 'overdue' ? 'liquidation_overdue' : 'liquidation_due'));
                        $sent++;
                    }
                });
            });

        return $sent;
    }

    private function decide(DisbursementRequest $request, string $status, User $by, ?string $note): void
    {
        $request->forceFill(['status' => $status, 'decided_by' => $by->id, 'decided_at' => now(), 'decision_note' => $note])->save();
        $request->requester?->notify(new DisbursementNotice($request, $status));
    }

    /** @param  list<string>  $statuses */
    private function expect(DisbursementRequest $request, array $statuses): void
    {
        if (! in_array($request->status, $statuses, true)) {
            abort(422, "This request is {$request->status}.");
        }
    }

    private function trust(callable $post): void
    {
        try {
            $post();
        } catch (InsufficientTrustFunds|LogicException $e) {
            abort(422, $e->getMessage());
        }
    }

    private function financeUsers(int $firmId)
    {
        return User::where('firm_id', $firmId)->where('is_active', true)->get()->filter(fn (User $u) => $u->role->canManageFinances())->values();
    }

    private function workingDay(CarbonImmutable $date): CarbonImmutable
    {
        while (! $this->calculator->isWorkingDay($date)) {
            $date = $date->addDay();
        }

        return $date;
    }
}
