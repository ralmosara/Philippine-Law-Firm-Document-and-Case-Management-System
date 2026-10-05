<?php

namespace App\Domain\Imports\Importers;

use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\InvoiceLine;
use App\Domain\Billing\Models\InvoicePayment;
use App\Domain\Billing\Models\TimeEntry;
use App\Domain\Billing\Services\InvoiceGenerator;
use App\Domain\Billing\Services\InvoicePayments;
use App\Domain\Imports\Values;
use App\Domain\Matters\Models\Firm;
use App\Domain\Matters\Models\Matter;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Invoices issued in the firm's previous system, with what has been paid
 * on them: the billing history (and receivables) of existing matters. They
 * keep their old numbers. History is recorded quietly: no e-invoice, no
 * email to the client, and payment reminders paused until the firm turns
 * them on for an invoice it still means to collect.
 */
class InvoiceImporter extends Importer
{
    /** Marks imported invoices and their imported payments. */
    public const MARKER = 'Imported from the previous billing system.';

    /** @var array<string, true> invoice numbers already in the system */
    private array $existing = [];

    private bool $vatRegistered = false;

    public function label(): string
    {
        return 'Invoices (billing history)';
    }

    public function ability(): string
    {
        return 'manage-finances';
    }

    public function modelClass(): string
    {
        return Invoice::class;
    }

    public function prepare(int $firmId): void
    {
        parent::prepare($firmId);
        $this->existing = Invoice::query()->pluck('number')->mapWithKeys(fn ($n) => [mb_strtoupper(trim($n)) => true])->all();
        $this->vatRegistered = (bool) Firm::whereKey($firmId)->value('vat_registered');
    }

    public function columns(): array
    {
        return [
            'number' => ['label' => 'Invoice number', 'required' => true, 'aliases' => ['number', 'invoice no', 'invoice', 'bill no', 'billing statement no', 'si no', 'or no'], 'example' => 'SI-2025-0457', 'hint' => 'As printed on the old invoice; kept as it is.'],
            'matter' => ['label' => 'Matter', 'required' => true, 'aliases' => ['matter reference', 'reference', 'case number', 'case no', 'docket'], 'example' => 'M-2026-0001', 'hint' => "The matter's reference or case number. The invoice is the matter's client's."],
            'issued_on' => ['label' => 'Issued on', 'required' => true, 'aliases' => ['date', 'invoice date', 'issue date', 'billing date'], 'example' => '03/15/2025'],
            'due_on' => ['label' => 'Due on', 'aliases' => ['due date', 'due'], 'example' => '04/14/2025', 'hint' => 'Blank: 30 days after issue.'],
            'fees' => ['label' => 'Professional fees', 'required' => true, 'aliases' => ['fees', 'amount', 'professional fee', 'fees php', 'subtotal'], 'example' => '50,000.00', 'hint' => 'Before VAT, in pesos.'],
            'vat' => ['label' => 'VAT', 'aliases' => ['vat amount', 'output vat', '12% vat'], 'example' => '6,000.00', 'hint' => 'Blank: 12% of the fees if the firm is VAT-registered, else none.'],
            'expenses' => ['label' => 'Expenses', 'aliases' => ['reimbursable expenses', 'disbursements', 'costs', 'out of pocket'], 'example' => '2,500.00', 'hint' => 'Filing fees and other costs billed at cost.'],
            'total' => ['label' => 'Total', 'aliases' => ['total amount', 'amount due', 'grand total'], 'example' => '58,500.00', 'hint' => 'Optional: checked against fees + VAT + expenses.'],
            'paid' => ['label' => 'Amount paid', 'aliases' => ['paid', 'payments', 'amount received', 'collected'], 'example' => '52,500.00', 'hint' => 'Cash received so far. Blank: unpaid.'],
            'withheld' => ['label' => 'Tax withheld', 'aliases' => ['withholding', 'cwt', 'ewt', 'withholding tax', '2307'], 'example' => '5,000.00', 'hint' => 'Creditable withholding tax the client deducted.'],
            'paid_on' => ['label' => 'Paid on', 'aliases' => ['payment date', 'date paid', 'or date'], 'example' => '04/10/2025', 'hint' => 'Date of the (last) payment. Blank: the issue date.'],
            'notes' => ['label' => 'Notes', 'aliases' => ['remarks', 'particulars', 'description'], 'example' => 'Retainer, March 2025'],
        ];
    }

    public function check(array $row): array
    {
        $errors = [];
        $warnings = [];

        $number = mb_substr(trim($row['number'] ?? ''), 0, 64);
        if ($number === '') {
            $errors[] = 'Invoice number is required.';
        }

        $matter = null;
        if (trim($row['matter'] ?? '') === '') {
            $errors[] = 'Matter is required.';
        } else {
            $matter = $this->findMatter($row['matter'], $error);
            if ($error) {
                $errors[] = $error;
            }
        }

        $issued = $this->date($row, 'issued_on', 'Issued on', $errors, required: true);
        $due = $this->date($row, 'due_on', 'Due on', $errors) ?? $issued?->addDays(30);
        $paidOn = $this->date($row, 'paid_on', 'Paid on', $errors) ?? $issued;
        if ($issued && $issued->gt(CarbonImmutable::today())) {
            $errors[] = 'Issued on is in the future.';
        }

        $fees = $this->money($row, 'fees', 'Professional fees', $errors, required: true);
        $expenses = $this->money($row, 'expenses', 'Expenses', $errors) ?? 0;
        $vat = $this->money($row, 'vat', 'VAT', $errors);
        if ($vat === null && $fees !== null) {
            $vat = $this->vatRegistered ? app(InvoiceGenerator::class)->vatOn($fees) : 0;
        }
        $paid = $this->money($row, 'paid', 'Amount paid', $errors) ?? 0;
        $withheld = $this->money($row, 'withheld', 'Tax withheld', $errors) ?? 0;

        $total = ($fees ?? 0) + ($vat ?? 0) + $expenses;
        $statedTotal = $this->money($row, 'total', 'Total', $errors);
        if ($statedTotal !== null && $fees !== null && abs($statedTotal - $total) > 1) {
            $errors[] = sprintf('Total %s does not equal fees + VAT + expenses (%s).', $this->peso($statedTotal), $this->peso($total));
        }
        if ($fees !== null && $paid + $withheld > $total) {
            $errors[] = sprintf('Paid and withheld (%s) are more than the total (%s).', $this->peso($paid + $withheld), $this->peso($total));
        }
        if ($fees !== null && $withheld > $fees) {
            $errors[] = 'Tax withheld cannot be more than the professional fees.';
        }
        if ($fees !== null && $fees <= 0 && $expenses <= 0) {
            $errors[] = 'The invoice has no amount.';
        }

        $values = [
            'number' => $number,
            'matter_id' => $matter?->id,
            'matter_reference' => $matter?->reference,
            'client_id' => $matter?->client_id,
            'issued_on' => $issued?->toDateString(),
            'due_on' => $due?->toDateString(),
            'fees_cents' => $fees,
            'vat_cents' => $vat,
            'expenses_cents' => $expenses,
            'total_cents' => $total,
            'paid_cents' => $paid,
            'withheld_cents' => $withheld,
            'paid_on' => $paidOn?->toDateString(),
            'notes' => trim($row['notes'] ?? '') ?: null,
        ];

        if ($errors !== []) {
            return $this->result($values, $errors);
        }

        if ($paid + $withheld < $total && $due && $due->lt(CarbonImmutable::today())) {
            $warnings[] = 'Still unpaid and past due: payment reminders stay paused for imported invoices until you turn them on.';
        }

        $key = mb_strtoupper($number);
        $duplicate = match (true) {
            isset($this->existing[$key]) => "Invoice {$number} is already in the system.",
            $this->seenInFile($key) => 'The same invoice number appears earlier in this file.',
            default => null,
        };

        return $this->result($values, duplicate: $duplicate, warnings: $warnings);
    }

    public function create(array $values, User $by): Model
    {
        return DB::transaction(function () use ($values, $by) {
            $matter = Matter::findOrFail($values['matter_id']);
            $invoice = Invoice::create([
                'firm_id' => $matter->firm_id,
                'client_id' => $matter->client_id,
                'matter_id' => $matter->id,
                'number' => $values['number'],
                'subtotal_cents' => $values['fees_cents'],
                'vat_cents' => $values['vat_cents'],
                'expenses_cents' => $values['expenses_cents'],
                'total_cents' => $values['total_cents'],
                'due_at' => $values['due_on'],
                'notes' => trim(self::MARKER.' '.($values['notes'] ?? '')),
                'created_by' => $by->id,
            ]);
            // Issued in the old system: recorded as such, without the side effects of issuing here.
            $invoice->forceFill(['status' => InvoiceStatus::Issued, 'issued_at' => $values['issued_on'], 'reminders_paused_at' => now()])->save();

            if ($values['fees_cents'] > 0) {
                InvoiceLine::create(['invoice_id' => $invoice->id, 'kind' => 'fee', 'description' => 'Professional fees'.($values['notes'] ? ": {$values['notes']}" : ''), 'amount_cents' => $values['fees_cents']]);
            }
            if ($values['expenses_cents'] > 0) {
                InvoiceLine::create(['invoice_id' => $invoice->id, 'kind' => 'expense', 'description' => 'Expenses', 'amount_cents' => $values['expenses_cents']]);
            }

            if ($values['paid_cents'] + $values['withheld_cents'] > 0) {
                app(InvoicePayments::class)->record($invoice, [
                    'received_on' => $values['paid_on'],
                    'method' => 'other',
                    'amount_cents' => $values['paid_cents'],
                    'withholding_cents' => $values['withheld_cents'],
                    'reference' => 'Imported',
                    'notes' => self::MARKER,
                ], $by);
            }

            return $invoice->refresh();
        });
    }

    public function undo(Model $model, User $by): ?string
    {
        /** @var Invoice $model */
        if (InvoicePayment::query()->where('invoice_id', $model->id)->where(fn ($q) => $q->whereNull('notes')->orWhere('notes', '!=', self::MARKER))->exists()) {
            return "Payments have been recorded on {$model->number} since the import.";
        }
        if (TimeEntry::query()->where('invoice_id', $model->id)->exists()) {
            return "Time entries are linked to {$model->number}; undo the time entry import first.";
        }
        if ($model->status === InvoiceStatus::Void) {
            return "{$model->number} has been voided since the import.";
        }

        DB::transaction(function () use ($model) {
            InvoicePayment::query()->where('invoice_id', $model->id)->delete();
            InvoiceLine::query()->where('invoice_id', $model->id)->delete();
            $model->delete();
        });

        return null;
    }

    /** @param list<string> $errors */
    private function date(array $row, string $key, string $label, array &$errors, bool $required = false): ?CarbonImmutable
    {
        $text = trim($row[$key] ?? '');
        if ($text === '') {
            if ($required) {
                $errors[] = "{$label} is required.";
            }

            return null;
        }
        $date = Values::date($text);
        if ($date === null) {
            $errors[] = "{$label} \"{$text}\" is not a date. Use 03/15/2025 (month first) or 2025-03-15.";
        }

        return $date;
    }

    /** @param list<string> $errors */
    private function money(array $row, string $key, string $label, array &$errors, bool $required = false): ?int
    {
        $text = trim($row[$key] ?? '');
        if ($text === '') {
            if ($required) {
                $errors[] = "{$label} is required.";
            }

            return null;
        }
        $cents = Values::cents($text);
        if ($cents === null || $cents < 0) {
            $errors[] = "{$label} \"{$text}\" is not an amount in pesos.";

            return null;
        }

        return $cents;
    }

    private function peso(int $cents): string
    {
        return '₱'.number_format($cents / 100, 2);
    }
}
