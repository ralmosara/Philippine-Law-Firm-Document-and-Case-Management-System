<?php

namespace App\Domain\Compliance\Services;

use App\Domain\Compliance\Models\AmlReview;
use App\Domain\Compliance\Notifications\AmlReviewNeeded;
use App\Domain\Matters\Models\Client;
use App\Domain\Matters\Models\Firm;
use App\Domain\Trust\Enums\TrustTransactionType;
use App\Domain\Trust\Models\TrustAccount;
use App\Domain\Trust\Models\TrustTransaction;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Flags a client's trust deposits on one day that together reach the
 * amount the firm treats as a covered transaction, so a managing partner
 * decides whether a report is due and records the decision. It does not
 * file anything with the AMLC; the threshold and the reporting rules are
 * the firm's to confirm with its compliance counsel.
 */
class AmlMonitor
{
    public function afterDeposit(TrustTransaction $transaction): ?AmlReview
    {
        if ($transaction->type !== TrustTransactionType::Deposit) {
            return null;
        }
        $account = TrustAccount::withoutGlobalScopes()->find($transaction->trust_account_id);
        $firm = $account ? Firm::find($account->firm_id) : null;
        if ($account === null || $firm === null) {
            return null;
        }

        $day = CarbonImmutable::parse($transaction->created_at)->timezone(config('app.timezone'));
        $accounts = TrustAccount::withoutGlobalScopes()->where('firm_id', $firm->id)->where('client_id', $account->client_id)->pluck('id');
        $deposits = TrustTransaction::query()->whereIn('trust_account_id', $accounts)
            ->where('type', TrustTransactionType::Deposit->value)
            ->whereBetween('created_at', [$day->startOfDay(), $day->endOfDay()])
            ->get(['id', 'amount_cents']);
        $total = (int) $deposits->sum('amount_cents');
        if ($total < $firm->aml_threshold_cents) {
            return null;
        }

        $created = false;
        $review = DB::transaction(function () use ($firm, $account, $day, $total, $deposits, &$created) {
            $review = AmlReview::withoutGlobalScopes()->where('firm_id', $firm->id)->where('client_id', $account->client_id)->whereDate('day', $day->toDateString())->lockForUpdate()->first();
            if ($review === null) {
                $created = true;

                return AmlReview::create(['firm_id' => $firm->id, 'client_id' => $account->client_id, 'day' => $day->toDateString(), 'amount_cents' => $total, 'trust_transaction_ids' => $deposits->pluck('id')->all()]);
            }
            $review->forceFill(['amount_cents' => $total, 'trust_transaction_ids' => $deposits->pluck('id')->all()])->save();

            return $review;
        });

        if ($created) {
            $client = Client::withoutGlobalScopes()->find($account->client_id);
            User::withoutGlobalScopes()->where('firm_id', $firm->id)->where('is_active', true)->where('role', Role::ManagingPartner->value)->get()
                ->each->notify(new AmlReviewNeeded($review, $client));
        }

        return $review;
    }

    /** @param 'not_reportable'|'reported' $status */
    public function decide(AmlReview $review, string $status, string $notes, ?string $reference, User $by): AmlReview
    {
        if ($status === 'reported' && blank($reference)) {
            throw ValidationException::withMessages(['report_reference' => 'Enter the reference of the report filed.']);
        }
        $review->forceFill(['status' => $status, 'notes' => $notes, 'report_reference' => $status === 'reported' ? $reference : null, 'decided_by' => $by->id, 'decided_at' => now()])->save();
        AuditLog::record('aml_review_decided', $review->firm_id, $by, $review->client, ['review' => $review->id, 'status' => $status, 'amount' => $review->amount_cents]);

        return $review;
    }
}
