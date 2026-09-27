<?php

namespace App\Domain\Billing\Collections;

use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Notifications\InvoiceIssued;
use App\Domain\Billing\Notifications\PaymentReminder;
use App\Domain\Billing\Services\InvoiceGenerator;
use App\Domain\Matters\Enums\FeeArrangement;
use App\Domain\Matters\Enums\MatterStatus;
use App\Domain\Matters\Models\Client;
use App\Domain\Matters\Models\Firm;
use App\Domain\Matters\Models\Matter;
use App\Domain\Trust\Models\TrustAccount;
use App\Domain\Trust\Notifications\TrustReplenishmentRequested;
use App\Enums\Role;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The follow-up that slips when a firm is busy: monthly retainer invoices,
 * payment reminders, and asking clients to top up trust deposits. Each
 * step claims its work first, so overlapping or repeated runs never bill
 * or e-mail twice.
 */
class Collections
{
    public const DUE_SOON_DAYS = 3;

    public const REPLENISH_EVERY_DAYS = 7;

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly InvoiceGenerator $invoices,
    ) {}

    /** @return array{retainers: int, reminders: int, replenishments: int} */
    public function run(?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();

        return [
            'retainers' => $this->billRetainers($today),
            'reminders' => $this->sendReminders($today),
            'replenishments' => $this->requestReplenishments(),
        ];
    }

    /** Invoice each opted-in retainer matter once a month, on its billing day. */
    public function billRetainers(CarbonImmutable $today): int
    {
        $month = $today->startOfMonth();
        $billed = 0;

        Matter::withoutGlobalScopes()
            ->where('fee_arrangement', FeeArrangement::Retainer->value)
            ->where('retainer_auto_bill', true)
            ->where('fixed_fee_cents', '>', 0)
            ->where('status', '!=', MatterStatus::Closed->value)
            ->whereNull('deleted_at')
            ->where('retainer_billing_day', '<=', $today->day)
            ->where(fn ($q) => $q->whereNull('retainer_billed_through')->orWhereDate('retainer_billed_through', '<', $month->toDateString()))
            ->orderBy('id')
            ->each(function (Matter $matter) use ($month, &$billed) {
                // Claim the month before billing it: a second run finds nothing to claim.
                $claimed = DB::table('matters')->where('id', $matter->id)
                    ->where(fn ($q) => $q->whereNull('retainer_billed_through')->orWhereDate('retainer_billed_through', '<', $month->toDateString()))
                    ->update(['retainer_billed_through' => $month->toDateString()]);
                if ($claimed !== 1) {
                    return;
                }

                try {
                    $this->tenant->runAs($matter->firm_id, fn () => $this->billRetainer($matter, $month));
                    $billed++;
                } catch (Throwable $e) {
                    // Give the month back so the next run retries, and say why.
                    DB::table('matters')->where('id', $matter->id)->update(['retainer_billed_through' => $matter->retainer_billed_through?->toDateString()]);
                    Log::error('Monthly retainer could not be billed', ['matter_id' => $matter->id, 'error' => $e->getMessage()]);
                }
            });

        return $billed;
    }

    private function billRetainer(Matter $matter, CarbonImmutable $month): void
    {
        $by = $this->biller($matter);
        $invoice = $this->invoices->generateForMatter(
            $matter, $by, timeEntryIds: [], expenseIds: [],
            feeLines: [['description' => 'Monthly retainer for '.$month->format('F Y'), 'amount_cents' => $matter->fixed_fee_cents]],
            notes: 'Monthly retainer, billed automatically.',
        );

        if ($matter->retainer_auto_issue) {
            $this->invoices->issue($invoice);
            $client = Client::find($matter->client_id);
            if ($client?->email) {
                $client->notify(new InvoiceIssued($invoice));
            }
        }
    }

    /** The responsible lawyer, else a managing partner, recorded as the invoice's author. */
    private function biller(Matter $matter): User
    {
        return User::withoutGlobalScopes()->where('is_active', true)->find($matter->responsible_lawyer_id)
            ?? User::withoutGlobalScopes()->where('firm_id', $matter->firm_id)->where('is_active', true)->where('role', Role::ManagingPartner->value)->orderBy('id')->firstOrFail();
    }

    /**
     * For firms that turned reminders on: a few days before the due date, then
     * a week and a month overdue. Each stage goes out once per invoice.
     */
    public function sendReminders(CarbonImmutable $today): int
    {
        $sent = 0;
        $firms = Firm::where('payment_reminders_enabled', true)->pluck('id');

        Invoice::withoutGlobalScopes()
            ->whereIn('firm_id', $firms)
            ->whereIn('status', InvoiceStatus::receivableValues())
            ->whereNull('reminders_paused_at')
            ->whereNotNull('due_at')
            ->whereDate('due_at', '<=', $today->addDays(self::DUE_SOON_DAYS)->toDateString())
            ->orderBy('id')
            ->each(function (Invoice $invoice) use ($today, &$sent) {
                $stage = $this->stageFor($invoice, $today);
                if ($stage === null) {
                    return;
                }

                $this->tenant->runAs($invoice->firm_id, function () use ($invoice, $stage, &$sent) {
                    if ($this->deliver($invoice, $stage, null)) {
                        $sent++;
                    }
                });
            });

        return $sent;
    }

    /** The latest stage an invoice has reached; overdue invoices skip stages they passed. */
    public function stageFor(Invoice $invoice, CarbonImmutable $today): ?string
    {
        $days = (int) CarbonImmutable::instance($invoice->due_at)->startOfDay()->diffInDays($today, false); // > 0: overdue

        return match (true) {
            $days >= 30 => InvoiceReminder::OVERDUE_30,
            $days >= 7 => InvoiceReminder::OVERDUE_7,
            $days >= -self::DUE_SOON_DAYS && $days < 0 => InvoiceReminder::DUE_SOON,
            default => null,
        };
    }

    /** A reminder sent by hand from the invoice. */
    public function remindNow(Invoice $invoice, User $by): InvoiceReminder
    {
        if (! $invoice->status->isReceivable() || $invoice->balanceDue() === 0) {
            throw ValidationException::withMessages(['invoice' => 'Only an issued, unpaid invoice can be followed up.']);
        }
        if (! Client::find($invoice->client_id)?->email) {
            throw ValidationException::withMessages(['invoice' => 'The client has no e-mail address.']);
        }

        return $this->deliver($invoice, InvoiceReminder::MANUAL, $by)
            ?? throw ValidationException::withMessages(['invoice' => 'A reminder was already sent today.']);
    }

    /**
     * Record the reminder and queue the e-mail, once per stage (a manual one
     * at most once a day), under a lock on the invoice.
     */
    private function deliver(Invoice $invoice, string $stage, ?User $by): ?InvoiceReminder
    {
        return DB::transaction(function () use ($invoice, $stage, $by) {
            $invoice = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $client = Client::find($invoice->client_id);

            $already = InvoiceReminder::where('invoice_id', $invoice->id)->where('stage', $stage)
                ->when($stage === InvoiceReminder::MANUAL, fn ($q) => $q->where('sent_at', '>=', now()->startOfDay()))
                ->exists();
            if ($already || ! $client?->email || $invoice->balanceDue() === 0) {
                return null;
            }

            $reminder = InvoiceReminder::create([
                'firm_id' => $invoice->firm_id,
                'invoice_id' => $invoice->id,
                'stage' => $stage,
                'balance_cents' => $invoice->balanceDue(),
                'sent_by' => $by?->id,
                'sent_at' => now(),
            ]);
            $client->notify(new PaymentReminder($invoice, $stage));

            return $reminder;
        });
    }

    /**
     * Clients whose trust deposit fell below the agreed minimum are asked to
     * top it up, at most once a week until it is back up.
     */
    public function requestReplenishments(): int
    {
        // Back above the minimum: the next shortfall starts a fresh request.
        TrustAccount::withoutGlobalScopes()->whereNotNull('replenishment_requested_at')->whereNotNull('minimum_balance_cents')
            ->whereColumn('balance_cents', '>=', 'minimum_balance_cents')
            ->update(['replenishment_requested_at' => null]);

        $sent = 0;
        TrustAccount::withoutGlobalScopes()
            ->where('status', 'open')
            ->whereNotNull('minimum_balance_cents')
            ->whereColumn('balance_cents', '<', 'minimum_balance_cents')
            ->where(fn ($q) => $q->whereNull('replenishment_requested_at')->orWhere('replenishment_requested_at', '<', now()->subDays(self::REPLENISH_EVERY_DAYS)))
            ->orderBy('id')
            ->each(function (TrustAccount $account) use (&$sent) {
                $this->tenant->runAs($account->firm_id, function () use ($account, &$sent) {
                    if ($this->askToReplenish($account)) {
                        $sent++;
                    }
                });
            });

        return $sent;
    }

    public function askToReplenish(TrustAccount $account): bool
    {
        return DB::transaction(function () use ($account) {
            $account = TrustAccount::whereKey($account->id)->lockForUpdate()->firstOrFail();
            $client = Client::find($account->client_id);
            $shortfall = (int) $account->minimum_balance_cents - $account->balance_cents;

            if ($shortfall <= 0 || ! $client?->email || $account->status !== 'open') {
                return false;
            }

            $account->forceFill(['replenishment_requested_at' => now()])->save();
            $client->notify(new TrustReplenishmentRequested($account, $shortfall));

            return true;
        });
    }
}
