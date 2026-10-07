<?php

namespace App\Domain\Billing\Services;

use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Models\Expense;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\TimeEntry;
use App\Domain\Billing\Notifications\InvoiceIssued;
use App\Domain\Matters\Models\Client;
use App\Domain\Matters\Models\Matter;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Month-end billing in one place: every matter with unbilled time or
 * expenses, drafted together, then reviewed and issued together. One
 * matter that cannot be billed does not stop the others; each result is
 * reported.
 */
class BillingRun
{
    public function __construct(private readonly InvoiceGenerator $invoices) {}

    /**
     * Matters with something unbilled, with the amounts and any drafts already waiting.
     *
     * @return list<array>
     */
    public function candidates(?string $through = null): array
    {
        $time = TimeEntry::query()->unbilled()->when($through, fn ($q) => $q->whereDate('work_date', '<=', $through))
            ->selectRaw('matter_id, COUNT(*) AS entries, SUM(minutes) AS minutes, SUM(amount_cents) AS amount, MIN(work_date) AS oldest')
            ->groupBy('matter_id')->get()->keyBy('matter_id');
        $expenses = Expense::query()->unbilled()->when($through, fn ($q) => $q->whereDate('expense_date', '<=', $through))
            ->selectRaw('matter_id, COUNT(*) AS entries, SUM(amount_cents) AS amount, MIN(expense_date) AS oldest')
            ->groupBy('matter_id')->get()->keyBy('matter_id');
        $drafts = Invoice::query()->where('status', InvoiceStatus::Draft->value)->selectRaw('matter_id, COUNT(*) AS n')->groupBy('matter_id')->pluck('n', 'matter_id');

        $ids = $time->keys()->merge($expenses->keys())->unique();

        return Matter::query()->whereIn('id', $ids)->with(['client:id,name,email', 'responsibleLawyer:id,name'])->get()
            ->map(function (Matter $m) use ($time, $expenses, $drafts) {
                $t = $time[$m->id] ?? null;
                $e = $expenses[$m->id] ?? null;

                return [
                    'matter' => ['id' => $m->id, 'reference' => $m->reference, 'title' => $m->title, 'fee_arrangement' => $m->fee_arrangement?->value],
                    'client' => $m->client ? ['id' => $m->client->id, 'name' => $m->client->name, 'has_email' => filled($m->client->email)] : null,
                    'lawyer' => $m->responsibleLawyer?->name,
                    'time_entries' => (int) ($t->entries ?? 0),
                    'minutes' => (int) ($t->minutes ?? 0),
                    'time_cents' => (int) ($t->amount ?? 0),
                    'expense_entries' => (int) ($e->entries ?? 0),
                    'expenses_cents' => (int) ($e->amount ?? 0),
                    'oldest' => collect([$t->oldest ?? null, $e->oldest ?? null])->filter()->min(),
                    'drafts' => (int) ($drafts[$m->id] ?? 0),
                ];
            })
            ->sortBy(fn ($r) => [$r['client']['name'] ?? '', $r['matter']['reference']])
            ->values()->all();
    }

    /**
     * Draft a bill for each matter: all its unbilled time and expenses (up to $through, if given).
     *
     * @param  list<int>  $matterIds
     * @return list<array{matter_id: int, invoice_id: ?int, number: ?string, total_cents: ?int, error: ?string}>
     */
    public function draft(array $matterIds, User $by, int $dueInDays, ?string $through = null): array
    {
        $results = [];
        foreach (Matter::query()->whereIn('id', $matterIds)->orderBy('id')->get() as $matter) {
            try {
                $timeIds = $through ? TimeEntry::query()->where('matter_id', $matter->id)->unbilled()->whereDate('work_date', '<=', $through)->pluck('id')->all() : null;
                $expenseIds = $through ? Expense::query()->where('matter_id', $matter->id)->unbilled()->whereDate('expense_date', '<=', $through)->pluck('id')->all() : null;
                $invoice = $this->invoices->generateForMatter($matter, $by, $timeIds, $dueInDays, null, $expenseIds);
                $results[] = ['matter_id' => $matter->id, 'invoice_id' => $invoice->id, 'number' => $invoice->number, 'total_cents' => $invoice->total_cents, 'error' => null];
            } catch (ValidationException $e) {
                $results[] = ['matter_id' => $matter->id, 'invoice_id' => null, 'number' => null, 'total_cents' => null, 'error' => collect($e->errors())->flatten()->first()];
            }
        }
        AuditLog::record('billing_run_drafted', $by->firm_id, $by, null, ['matters' => count($matterIds), 'drafted' => count(array_filter($results, fn ($r) => $r['invoice_id']))]);

        return $results;
    }

    /**
     * Issue drafts together, optionally emailing each client their billing statement.
     *
     * @param  list<int>  $invoiceIds
     * @return list<array{invoice_id: int, number: string, issued: bool, emailed: bool, error: ?string}>
     */
    public function issue(array $invoiceIds, User $by, bool $email): array
    {
        $results = [];
        foreach (Invoice::query()->whereIn('id', $invoiceIds)->orderBy('id')->get() as $invoice) {
            try {
                DB::transaction(fn () => $this->invoices->issue($invoice));
                $client = Client::find($invoice->client_id);
                $emailed = false;
                if ($email && filled($client?->email)) {
                    $client->notify(new InvoiceIssued($invoice->fresh()));
                    $emailed = true;
                }
                $results[] = ['invoice_id' => $invoice->id, 'number' => $invoice->number, 'issued' => true, 'emailed' => $emailed, 'error' => null];
            } catch (ValidationException $e) {
                $results[] = ['invoice_id' => $invoice->id, 'number' => $invoice->number, 'issued' => false, 'emailed' => false, 'error' => collect($e->errors())->flatten()->first()];
            } catch (Throwable $e) {
                report($e);
                $results[] = ['invoice_id' => $invoice->id, 'number' => $invoice->number, 'issued' => false, 'emailed' => false, 'error' => 'Could not be issued; try it from the invoice.'];
            }
        }
        AuditLog::record('billing_run_issued', $by->firm_id, $by, null, ['invoices' => count($invoiceIds), 'emailed' => $email]);

        return $results;
    }
}
