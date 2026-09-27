<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Billing\Collections\Collections;
use App\Domain\Billing\Collections\InvoiceReminder;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Matters\Enums\FeeArrangement;
use App\Domain\Matters\Enums\MatterStatus;
use App\Domain\Matters\Models\Firm;
use App\Domain\Matters\Models\Matter;
use App\Domain\Trust\Models\TrustAccount;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/** What needs following up: unpaid invoices, low trust deposits, retainers to bill. */
class CollectionsController extends Controller
{
    public function __construct(private readonly Collections $collections) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('manage-finances');
        $today = CarbonImmutable::today();

        $invoices = Invoice::query()
            ->whereIn('status', InvoiceStatus::receivableValues())
            ->whereDate('due_at', '<=', $today->addDays(Collections::DUE_SOON_DAYS)->toDateString())
            ->with(['client:id,name,email', 'matter:id,reference,title', 'reminders' => fn ($q) => $q->latest('sent_at')])
            ->orderBy('due_at')
            ->get();

        return response()->json([
            'reminders_enabled' => (bool) Firm::findOrFail($request->user()->firm_id)->payment_reminders_enabled,
            'invoices' => $invoices->map(fn (Invoice $i) => [
                'id' => $i->id,
                'number' => $i->number,
                'client' => $i->client?->name,
                'client_has_email' => filled($i->client?->email),
                'matter' => $i->matter?->reference,
                'due_at' => $i->due_at?->toDateString(),
                'days_overdue' => (int) CarbonImmutable::instance($i->due_at)->diffInDays($today, false),
                'balance_cents' => $i->balanceDue(),
                'reminders_paused' => $i->reminders_paused_at !== null,
                'last_reminder' => $i->reminders->first() ? [
                    'label' => InvoiceReminder::LABELS[$i->reminders->first()->stage] ?? $i->reminders->first()->stage,
                    'sent_at' => $i->reminders->first()->sent_at->toIso8601String(),
                ] : null,
                'next_reminder' => $i->reminders_paused_at === null && ! $i->reminders->contains('stage', $this->collections->stageFor($i, $today))
                    ? (InvoiceReminder::LABELS[$this->collections->stageFor($i, $today) ?? ''] ?? null)
                    : null,
            ])->values(),
            'trust_below_minimum' => TrustAccount::query()->where('status', 'open')->whereNotNull('minimum_balance_cents')
                ->whereColumn('balance_cents', '<', 'minimum_balance_cents')
                ->with('client:id,name,email')->orderBy('account_number')->get()
                ->map(fn (TrustAccount $a) => [
                    'id' => $a->id,
                    'account_number' => $a->account_number,
                    'client' => $a->client?->name,
                    'client_has_email' => filled($a->client?->email),
                    'balance_cents' => $a->balance_cents,
                    'minimum_balance_cents' => $a->minimum_balance_cents,
                    'replenishment_requested_at' => $a->replenishment_requested_at?->toIso8601String(),
                ])->values(),
            'retainers' => Matter::query()
                ->where('fee_arrangement', FeeArrangement::Retainer->value)
                ->where('status', '!=', MatterStatus::Closed->value)
                ->where('fixed_fee_cents', '>', 0)
                ->with('client:id,name')->orderBy('reference')->get()
                ->map(fn (Matter $m) => [
                    'id' => $m->id,
                    'reference' => $m->reference,
                    'title' => $m->title,
                    'client' => $m->client?->name,
                    'amount_cents' => $m->fixed_fee_cents,
                    'auto_bill' => $m->retainer_auto_bill,
                    'auto_issue' => $m->retainer_auto_issue,
                    'billing_day' => $m->retainer_billing_day,
                    'billed_through' => $m->retainer_billed_through?->format('F Y'),
                    'next_billing' => $m->retainer_auto_bill ? $this->nextBilling($m, $today)->toDateString() : null,
                ])->values(),
        ]);
    }

    public function remind(Request $request, Invoice $invoice): JsonResponse
    {
        Gate::authorize('manage-finances');
        $reminder = $this->collections->remindNow($invoice, $request->user());

        return response()->json(['sent_at' => $reminder->sent_at->toIso8601String()], 201);
    }

    public function pauseReminders(Request $request, Invoice $invoice): JsonResponse
    {
        Gate::authorize('manage-finances');
        $paused = $request->validate(['paused' => ['required', 'boolean']])['paused'];
        $invoice->forceFill(['reminders_paused_at' => $paused ? now() : null])->save();

        return response()->json(['reminders_paused_at' => $invoice->reminders_paused_at?->toIso8601String()]);
    }

    public function setMinimum(Request $request, TrustAccount $trustAccount): JsonResponse
    {
        Gate::authorize('manage-finances');
        $validated = $request->validate(['minimum_balance_cents' => ['nullable', 'integer', 'min:1', 'max:2000000000']]);
        $trustAccount->forceFill(['minimum_balance_cents' => $validated['minimum_balance_cents'] ?? null])->save();

        return response()->json(['minimum_balance_cents' => $trustAccount->minimum_balance_cents]);
    }

    public function requestReplenishment(TrustAccount $trustAccount): JsonResponse
    {
        Gate::authorize('manage-finances');
        if ($trustAccount->minimum_balance_cents === null || $trustAccount->balance_cents >= $trustAccount->minimum_balance_cents) {
            throw ValidationException::withMessages(['account' => 'The account is not below its minimum balance.']);
        }
        if (! $this->collections->askToReplenish($trustAccount)) {
            throw ValidationException::withMessages(['account' => 'The client has no e-mail address.']);
        }

        return response()->json(['replenishment_requested_at' => $trustAccount->fresh()->replenishment_requested_at?->toIso8601String()], 201);
    }

    private function nextBilling(Matter $matter, CarbonImmutable $today): CarbonImmutable
    {
        $thisMonth = $today->startOfMonth();
        $billedThisMonth = $matter->retainer_billed_through !== null && ! CarbonImmutable::instance($matter->retainer_billed_through)->lt($thisMonth);
        $month = $billedThisMonth ? $thisMonth->addMonth() : $thisMonth;
        $day = $month->day($matter->retainer_billing_day);

        return $day->lt($today) ? $today : $day;
    }
}
