<?php

namespace App\Domain\Imports\Importers;

use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\TimeEntry;
use App\Domain\Imports\Values;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Time recorded in the firm's previous system. Entries tied to an invoice
 * (by its number, imported first) count as billed; the others are unbilled
 * work in progress, ready to invoice here. Either way they feed matter
 * profitability, budgets and lawyer utilization.
 */
class TimeEntryImporter extends Importer
{
    /** @var array<string, true> matter|user|date|minutes|description already in the system */
    private array $existing = [];

    /** @var Collection<string, Invoice>|null */
    private ?Collection $invoices = null;

    public function label(): string
    {
        return 'Time entries';
    }

    public function ability(): string
    {
        return 'manage-finances';
    }

    public function modelClass(): string
    {
        return TimeEntry::class;
    }

    public function prepare(int $firmId): void
    {
        parent::prepare($firmId);
        $this->existing = [];
        TimeEntry::query()->get(['matter_id', 'user_id', 'work_date', 'minutes', 'description'])
            ->each(fn (TimeEntry $e) => $this->existing[$this->key($e->matter_id, $e->user_id, $e->work_date->toDateString(), $e->minutes, (string) $e->description)] = true);
        $this->invoices = Invoice::query()->where('status', '!=', InvoiceStatus::Void->value)->get(['id', 'number', 'matter_id', 'status'])->keyBy(fn ($i) => mb_strtoupper(trim($i->number)));
    }

    public function columns(): array
    {
        return [
            'matter' => ['label' => 'Matter', 'required' => true, 'aliases' => ['matter reference', 'reference', 'case number', 'case no', 'docket'], 'example' => 'M-2026-0001'],
            'date' => ['label' => 'Date', 'required' => true, 'aliases' => ['work date', 'date of service', 'entry date'], 'example' => '03/10/2025'],
            'lawyer' => ['label' => 'Lawyer', 'required' => true, 'aliases' => ['timekeeper', 'attorney', 'user', 'staff', 'by'], 'example' => 'ceo@demofirm.ph', 'hint' => 'E-mail or full name of an active user.'],
            'hours' => ['label' => 'Hours', 'required' => true, 'aliases' => ['time', 'duration', 'hrs', 'time spent'], 'example' => '1.5', 'hint' => '1.5, 1:30 or 90m.'],
            'description' => ['label' => 'Description', 'required' => true, 'aliases' => ['activity', 'work done', 'narrative', 'particulars', 'task'], 'example' => 'Drafted the answer'],
            'rate' => ['label' => 'Hourly rate', 'aliases' => ['rate', 'billing rate', 'rate per hour'], 'example' => '5,000.00', 'hint' => "In pesos. Blank: the lawyer's standard rate."],
            'billable' => ['label' => 'Billable', 'aliases' => ['chargeable', 'bill'], 'example' => 'yes', 'hint' => 'yes or no. Blank: yes.'],
            'invoice' => ['label' => 'Invoice number', 'aliases' => ['invoice', 'invoice no', 'billed on', 'bill no', 'billed'], 'example' => 'SI-2025-0457', 'hint' => 'Already billed on this invoice (import invoices first). Blank: unbilled.'],
        ];
    }

    public function check(array $row): array
    {
        $errors = [];
        $warnings = [];

        $matter = null;
        if (trim($row['matter'] ?? '') === '') {
            $errors[] = 'Matter is required.';
        } else {
            $matter = $this->findMatter($row['matter'], $error);
            if ($error) {
                $errors[] = $error;
            }
        }

        $date = Values::date($row['date'] ?? '');
        if (trim($row['date'] ?? '') === '') {
            $errors[] = 'Date is required.';
        } elseif ($date === null) {
            $errors[] = "Date \"{$row['date']}\" is not a date. Use 03/10/2025 (month first) or 2025-03-10.";
        } elseif ($date->gt(CarbonImmutable::today())) {
            $errors[] = 'Date is in the future.';
        }

        $user = null;
        if (trim($row['lawyer'] ?? '') === '') {
            $errors[] = 'Lawyer is required.';
        } else {
            $user = $this->findUser($row['lawyer'], $error);
            if ($error) {
                $errors[] = $error;
            }
        }

        $minutes = self::minutes($row['hours'] ?? '');
        if (trim($row['hours'] ?? '') === '') {
            $errors[] = 'Hours is required.';
        } elseif ($minutes === null || $minutes <= 0 || $minutes > 24 * 60) {
            $errors[] = "Hours \"{$row['hours']}\" should look like 1.5, 1:30 or 90m (at most 24 hours).";
        }

        $description = trim($row['description'] ?? '');
        if ($description === '') {
            $errors[] = 'Description is required.';
        }

        $rate = null;
        if (trim($row['rate'] ?? '') !== '') {
            $rate = Values::cents($row['rate']);
            if ($rate === null || $rate < 0) {
                $errors[] = "Hourly rate \"{$row['rate']}\" is not an amount in pesos.";
            }
        } elseif ($user) {
            $rate = (int) User::whereKey($user->id)->value('hourly_rate_cents');
        }

        $billable = match (mb_strtolower(trim($row['billable'] ?? ''))) {
            '', 'yes', 'y', 'true', '1', 'oo' => true,
            'no', 'n', 'false', '0', 'hindi' => false,
            default => null,
        };
        if ($billable === null) {
            $errors[] = "Billable \"{$row['billable']}\" should be yes or no.";
        }

        $invoice = null;
        if (trim($row['invoice'] ?? '') !== '') {
            $invoice = $this->invoices?->get(mb_strtoupper(trim($row['invoice'])));
            if ($invoice === null) {
                $errors[] = "No invoice numbered \"{$row['invoice']}\". Import the invoices first.";
            } elseif ($matter && (int) $invoice->matter_id !== (int) $matter->id) {
                $errors[] = "Invoice {$invoice->number} is for another matter.";
            }
        }
        if ($billable && $invoice === null && $date && $date->lt(CarbonImmutable::today()->subYear())) {
            $warnings[] = 'Over a year old and not linked to an invoice: it will show as unbilled time, ready to invoice.';
        }

        $values = [
            'matter_id' => $matter?->id,
            'matter_reference' => $matter?->reference,
            'user_id' => $user?->id,
            'work_date' => $date?->toDateString(),
            'minutes' => $minutes,
            'description' => mb_substr($description, 0, 2000),
            'rate_cents' => $rate,
            'is_billable' => (bool) $billable,
            'invoice_id' => $invoice?->id,
            'invoice_number' => $invoice?->number,
        ];

        if ($errors !== []) {
            return $this->result($values, $errors);
        }

        $key = $this->key($matter->id, $user->id, $values['work_date'], $minutes, $description);
        $duplicate = match (true) {
            isset($this->existing[$key]) => 'The same time entry is already in the system.',
            $this->seenInFile($key) => 'The same time entry appears earlier in this file.',
            default => null,
        };

        return $this->result($values, duplicate: $duplicate, warnings: $warnings);
    }

    /** "1.5" or "1,5" hours, "1:30", "90m", "2h" → minutes. */
    public static function minutes(string $value): ?int
    {
        $value = mb_strtolower(str_replace([' ', ','], ['', '.'], trim($value)));

        return match (true) {
            (bool) preg_match('/^(\d+):([0-5]\d)$/', $value, $m) => (int) $m[1] * 60 + (int) $m[2],
            (bool) preg_match('/^(\d+)m(in)?s?$/', $value, $m) => (int) $m[1],
            (bool) preg_match('/^(\d+(\.\d+)?)h(rs?|ours?)?$/', $value, $m), (bool) preg_match('/^(\d+(\.\d+)?)$/', $value, $m) => (int) round((float) $m[1] * 60),
            default => null,
        };
    }

    private function key(int $matterId, int $userId, string $date, int $minutes, string $description): string
    {
        return "{$matterId}|{$userId}|{$date}|{$minutes}|".Values::nameKey($description);
    }

    public function create(array $values, User $by): Model
    {
        $entry = TimeEntry::create([
            'firm_id' => $by->firm_id,
            'matter_id' => $values['matter_id'],
            'user_id' => $values['user_id'],
            'work_date' => $values['work_date'],
            'minutes' => $values['minutes'],
            'rate_cents' => (int) $values['rate_cents'],
            'description' => $values['description'],
            'is_billable' => $values['is_billable'],
        ]);
        if ($values['invoice_id']) {
            $entry->forceFill(['invoice_id' => $values['invoice_id']])->save();
        }

        return $entry;
    }

    public function undo(Model $model, User $by): ?string
    {
        /** @var TimeEntry $model */
        // Billed here since the import (not on an imported invoice): leave it.
        if ($model->invoice_id !== null) {
            $invoice = Invoice::query()->find($model->invoice_id, ['id', 'number', 'notes']);
            if ($invoice && ! str_starts_with((string) $invoice->notes, InvoiceImporter::MARKER)) {
                return "Billed on {$invoice->number} since the import.";
            }
        }

        $model->delete();

        return null;
    }
}
