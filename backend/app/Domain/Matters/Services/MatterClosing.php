<?php

namespace App\Domain\Matters\Services;

use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Models\Expense;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\TimeEntry;
use App\Domain\Deadlines\Enums\DeadlineKind;
use App\Domain\Deadlines\Enums\DeadlineStatus;
use App\Domain\Deadlines\Models\MatterDeadline;
use App\Domain\Documents\Actions\CreateDocumentVersion;
use App\Domain\Documents\Models\Document;
use App\Domain\Matters\Enums\MatterStatus;
use App\Domain\Matters\Models\Client;
use App\Domain\Matters\Models\Firm;
use App\Domain\Matters\Models\Matter;
use App\Domain\Trust\Models\TrustAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * What must be settled before a matter is closed. Client money still held
 * in trust for the matter blocks closing (it must be returned or applied to
 * a bill first: CPRA Canon III, Secs. 49-50); unbilled work, unpaid bills
 * and open deadlines are shown so they are closed knowingly. Then a
 * closing letter can be drafted for the client.
 */
class MatterClosing
{
    /**
     * @return array{blockers: list<array{key: string, message: string}>, warnings: list<array{key: string, message: string}>}
     */
    public function check(Matter $matter): array
    {
        $blockers = [];
        $warnings = [];

        $held = TrustAccount::query()->where('matter_id', $matter->id)->where('balance_cents', '>', 0)->get(['account_number', 'balance_cents']);
        foreach ($held as $account) {
            $blockers[] = ['key' => 'trust', 'message' => "{$this->peso($account->balance_cents)} is still held in trust ({$account->account_number}). Return it to the client or apply it to a bill first."];
        }

        // Funds in the client's general trust account: block only when this is the client's last open matter.
        $general = (int) TrustAccount::query()->where('client_id', $matter->client_id)->whereNull('matter_id')->where('balance_cents', '>', 0)->sum('balance_cents');
        if ($general > 0) {
            $others = Matter::query()->where('client_id', $matter->client_id)->whereKeyNot($matter->id)->where('status', '!=', MatterStatus::Closed->value)->exists();
            $message = "The client has {$this->peso($general)} in their general trust account.";
            $others
                ? $warnings[] = ['key' => 'client_trust', 'message' => $message.' They have other open matters, so it can stay.']
                : $blockers[] = ['key' => 'client_trust', 'message' => $message.' This is their last open matter: return it or apply it to a bill first.'];
        }

        $time = TimeEntry::query()->where('matter_id', $matter->id)->unbilled();
        if ($count = (clone $time)->count()) {
            $warnings[] = ['key' => 'unbilled_time', 'message' => "{$count} unbilled time ".($count === 1 ? 'entry' : 'entries').' worth '.$this->peso((int) $time->sum('amount_cents')).'. Bill it, or mark it non-billable if it will not be charged.'];
        }
        $expenses = Expense::query()->where('matter_id', $matter->id)->unbilled();
        if ($count = (clone $expenses)->count()) {
            $warnings[] = ['key' => 'unbilled_expenses', 'message' => "{$count} unbilled ".($count === 1 ? 'expense' : 'expenses').' of '.$this->peso((int) $expenses->sum('amount_cents')).'.'];
        }
        if ($count = Invoice::query()->where('matter_id', $matter->id)->where('status', InvoiceStatus::Draft->value)->count()) {
            $warnings[] = ['key' => 'draft_invoices', 'message' => "{$count} draft ".($count === 1 ? 'bill' : 'bills').' not yet issued.'];
        }
        $unpaid = Invoice::query()->where('matter_id', $matter->id)->whereIn('status', InvoiceStatus::receivableValues())->get();
        if ($unpaid->isNotEmpty()) {
            $warnings[] = ['key' => 'unpaid', 'message' => $this->peso((int) $unpaid->sum(fn (Invoice $i) => $i->balanceDue()))." unpaid on {$unpaid->count()} ".($unpaid->count() === 1 ? 'bill' : 'bills').'. Collection reminders continue after closing.'];
        }
        $open = MatterDeadline::query()->where('matter_id', $matter->id)->whereIn('status', [DeadlineStatus::Pending->value, DeadlineStatus::Missed->value])->get(['kind']);
        if ($open->isNotEmpty()) {
            $tasks = $open->where('kind', DeadlineKind::Task)->count();
            $deadlines = $open->count() - $tasks;
            $parts = array_filter([$deadlines ? "{$deadlines} open ".($deadlines === 1 ? 'deadline or hearing' : 'deadlines and hearings') : null, $tasks ? "{$tasks} open ".($tasks === 1 ? 'task' : 'tasks') : null]);
            $warnings[] = ['key' => 'open_deadlines', 'message' => ucfirst(implode(' and ', $parts)).'. They are cancelled when the matter closes.'];
        }

        return ['blockers' => $blockers, 'warnings' => $warnings];
    }

    /** On closing: open deadlines and tasks are cancelled, so no reminders go out for a closed matter. */
    public function afterClosed(Matter $matter, User $by): void
    {
        MatterDeadline::query()->where('matter_id', $matter->id)->whereIn('status', [DeadlineStatus::Pending->value, DeadlineStatus::Missed->value])->get()
            ->each(function (MatterDeadline $deadline) use ($by) {
                $deadline->forceFill(['status' => DeadlineStatus::Cancelled])->save();
                $deadline->logEvent('cancelled', $by, ['reason' => 'Matter closed']);
            });
    }

    /** A closing letter to the client, as a draft document on the matter, to edit and send on letterhead. */
    public function draftLetter(Matter $matter, User $by): Document
    {
        $matter->loadMissing(['client', 'responsibleLawyer']);
        $firm = Firm::findOrFail($matter->firm_id);
        $client = $matter->client ?? new Client;
        $lawyer = $matter->responsibleLawyer ?? $by;
        $reason = $matter->statusEvents()->where('to_status', MatterStatus::Closed->value)->latest('id')->value('reason');
        $years = $firm->retention_years ?: 10;
        $held = TrustAccount::query()->where('matter_id', $matter->id)->exists();

        $text = implode("\n\n", array_filter([
            now()->format('F j, Y'),
            implode("\n", array_filter([$client->name, $client->address])),
            "Re: {$matter->title}".($matter->case_number ? " ({$matter->case_number})" : '')."\nOur reference: {$matter->reference}",
            'Dear '.($client->name ?: 'Client').':',
            'We write to confirm that our work on the above matter has concluded and that we have closed our file.'.($reason ? " {$reason}" : ''),
            $held ? 'All funds you deposited with us for this matter have been accounted for; the statement of your trust account is enclosed.' : null,
            'Any original documents you entrusted to us are returned with this letter or are ready for collection at our office. Please let us know if you would like copies of anything else in the file.',
            "We will keep our file for {$years} years from today, after which it will be securely destroyed in accordance with the Data Privacy Act. If anything in this matter requires further action, including any deadline that may arise in the future, please contact us; unless you instruct us again, we will take no further steps.",
            'Thank you for entrusting this matter to us. It has been our privilege to serve you.',
            "Very truly yours,\n\n\n".mb_strtoupper($lawyer->name)."\n{$firm->name}",
        ]));

        return DB::transaction(function () use ($matter, $by, $text) {
            $document = $matter->documents()->create([
                'firm_id' => $matter->firm_id,
                'title' => "Closing letter — {$matter->title}",
                'created_by' => $by->id,
            ]);
            app(CreateDocumentVersion::class)->execute($document, $text, $by, 'Closing letter drafted');

            return $document;
        });
    }

    private function peso(int $cents): string
    {
        return '₱'.number_format($cents / 100, 2);
    }
}
