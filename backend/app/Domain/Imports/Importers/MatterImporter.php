<?php

namespace App\Domain\Imports\Importers;

use App\Domain\Imports\Values;
use App\Domain\Matters\Enums\MatterStatus;
use App\Domain\Matters\Enums\PartyRole;
use App\Domain\Matters\Models\Matter;
use App\Http\Controllers\Api\V1\LookupController;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Existing cases, at whatever stage they are. Imported matters do not get
 * the intake workflow checklist: they are already under way.
 */
class MatterImporter extends Importer
{
    public function label(): string
    {
        return 'Matters';
    }

    public function ability(): string
    {
        return 'manage-firm';
    }

    public function modelClass(): string
    {
        return Matter::class;
    }

    public function columns(): array
    {
        return [
            'client' => ['label' => 'Client', 'required' => true, 'aliases' => ['client name', 'client email', 'client e-mail'], 'example' => 'Luzon Logistics & Freight Corp.', 'hint' => "The client's name or e-mail, exactly as imported."],
            'title' => ['label' => 'Title', 'required' => true, 'aliases' => ['matter', 'matter title', 'case title', 'caption', 'case name'], 'example' => 'Luzon Logistics v. Fernandez'],
            'case_type' => ['label' => 'Case type', 'aliases' => ['type', 'nature', 'nature of case', 'practice area'], 'example' => 'Civil', 'hint' => implode(', ', LookupController::CASE_TYPES).'. Blank: Civil.'],
            'case_number' => ['label' => 'Case number', 'aliases' => ['docket', 'docket no', 'docket number', 'case no', 'civil case no', 'criminal case no'], 'example' => 'CV-2026-5220'],
            'court' => ['label' => 'Court', 'aliases' => ['tribunal', 'forum', 'venue'], 'example' => 'Regional Trial Court, Manila'],
            'court_branch' => ['label' => 'Branch', 'aliases' => ['court branch', 'sala', 'branch no'], 'example' => 'Branch 21'],
            'judge' => ['label' => 'Judge', 'aliases' => ['presiding judge'], 'example' => 'Hon. Ana Cruz'],
            'opened_at' => ['label' => 'Date opened', 'aliases' => ['opened', 'opened on', 'date engaged', 'engagement date', 'date filed'], 'example' => '03/15/2026', 'hint' => 'Month first: 03/15/2026, or 2026-03-15.'],
            'status' => ['label' => 'Status', 'aliases' => ['stage'], 'example' => 'pre-trial', 'hint' => 'intake, filed, pre-trial, trial, decision, appeal or closed. Blank: filed when there is a case number, else intake.'],
            'responsible_lawyer' => ['label' => 'Responsible lawyer', 'aliases' => ['lawyer', 'handling lawyer', 'attorney', 'assigned lawyer', 'counsel'], 'example' => 'ceo@demofirm.ph', 'hint' => 'E-mail or full name of a lawyer in the firm.'],
            'reference' => ['label' => 'Reference', 'aliases' => ['file no', 'file number', 'our ref', 'reference no', 'matter no'], 'example' => 'LIT-2019-044', 'hint' => 'Your existing file number, kept as the matter reference. Blank: a new M-YYYY-NNNN.'],
            'opposing_party' => ['label' => 'Opposing party', 'aliases' => ['adverse party', 'opponent', 'versus', 'vs', 'other party'], 'example' => 'Ramon Fernandez', 'hint' => 'Separate several with semicolons. Used in conflict checks.'],
            'opposing_counsel' => ['label' => 'Opposing counsel', 'aliases' => ['adverse counsel'], 'example' => 'Atty. Jose Rizal'],
            'description' => ['label' => 'Description', 'aliases' => ['notes', 'remarks', 'summary'], 'example' => 'Collection of unpaid freight charges'],
        ];
    }

    public function check(array $row): array
    {
        $errors = [];
        $warnings = [];

        $client = null;
        if (trim($row['client'] ?? '') === '') {
            $errors[] = 'Client is required.';
        } else {
            $client = $this->findClient($row['client'], $error);
            if ($error) {
                $errors[] = $error;
            }
        }

        $title = trim($row['title'] ?? '');
        if ($title === '') {
            $errors[] = 'Title is required.';
        }

        $caseType = trim($row['case_type'] ?? '');
        $known = collect(LookupController::CASE_TYPES)->first(fn ($t) => strcasecmp($t, $caseType) === 0);
        if ($caseType === '') {
            $caseType = 'Civil';
            $warnings[] = 'No case type given; set to Civil.';
        } elseif ($known) {
            $caseType = $known;
        } elseif (mb_strlen($caseType) > 64) {
            $errors[] = 'Case type is longer than 64 characters.';
        }

        $opened = null;
        if (trim($row['opened_at'] ?? '') !== '') {
            $opened = Values::date($row['opened_at']);
            if ($opened === null) {
                $errors[] = "Date opened \"{$row['opened_at']}\" is not a date. Use 03/15/2026 (month first) or 2026-03-15.";
            } elseif ($opened->isFuture()) {
                $errors[] = 'Date opened is in the future.';
            }
        }

        $caseNumber = trim($row['case_number'] ?? '') ?: null;
        $statusText = str_replace([' ', '-'], '_', mb_strtolower(trim($row['status'] ?? '')));
        $status = $statusText === '' ? ($caseNumber ? MatterStatus::Filed : MatterStatus::Intake) : MatterStatus::tryFrom($statusText);
        if ($status === null) {
            $errors[] = "Status \"{$row['status']}\" should be one of: intake, filed, pre-trial, trial, decision, appeal, closed.";
        }

        $lawyer = null;
        if (trim($row['responsible_lawyer'] ?? '') !== '') {
            $lawyer = $this->findUser($row['responsible_lawyer'], $error);
            if ($error) {
                $errors[] = $error;
            } elseif (! $lawyer->role->isLawyer()) {
                $errors[] = "{$lawyer->name} is not a lawyer.";
            }
        }

        $reference = trim($row['reference'] ?? '') ?: null;
        if ($reference !== null && mb_strlen($reference) > 32) {
            $errors[] = 'Reference is longer than 32 characters.';
        }

        $values = [
            'client_id' => $client?->id,
            'client_name' => $client?->name,
            'title' => mb_substr($title, 0, 255),
            'case_type' => $caseType,
            'case_number' => $caseNumber ? mb_substr($caseNumber, 0, 64) : null,
            'court' => mb_substr(trim($row['court'] ?? ''), 0, 255) ?: null,
            'court_branch' => mb_substr(trim($row['court_branch'] ?? ''), 0, 255) ?: null,
            'judge' => mb_substr(trim($row['judge'] ?? ''), 0, 255) ?: null,
            'opened_at' => $opened?->toDateString(),
            'status' => $status?->value,
            'responsible_lawyer_id' => $lawyer?->id,
            'reference' => $reference,
            'opposing_parties' => array_values(array_filter(array_map('trim', explode(';', $row['opposing_party'] ?? '')))),
            'opposing_counsel' => trim($row['opposing_counsel'] ?? '') ?: null,
            'description' => trim($row['description'] ?? '') ?: null,
        ];

        if ($errors !== []) {
            return $this->result($values, $errors, warnings: $warnings);
        }

        return $this->result($values, duplicate: $this->duplicate($values), warnings: $warnings);
    }

    private function duplicate(array $values): ?string
    {
        $titleKey = Values::nameKey($values['title']);
        $existing = $this->matters()->first(fn (Matter $m) => ($values['reference'] && strcasecmp($m->reference, $values['reference']) === 0)
            || ($values['case_number'] && $m->case_number && self::docketKey($m->case_number) === self::docketKey($values['case_number']))
            || ($m->client_id === $values['client_id'] && Values::nameKey($m->title) === $titleKey));

        if ($existing) {
            return "Already a matter: {$existing->reference} {$existing->title}.";
        }
        if ($values['reference'] && Matter::onlyTrashed()->where('reference', $values['reference'])->exists()) {
            return "Reference {$values['reference']} belongs to a deleted matter.";
        }

        $keys = array_filter([
            'm:'.$values['client_id'].':'.$titleKey,
            $values['case_number'] ? 'c:'.self::docketKey($values['case_number']) : null,
            $values['reference'] ? 'r:'.mb_strtolower($values['reference']) : null,
        ]);
        $repeated = false;
        foreach ($keys as $key) {
            $repeated = $this->seenInFile($key) || $repeated;
        }

        return $repeated ? 'The same matter appears earlier in this file.' : null;
    }

    public function create(array $values, User $by): Model
    {
        return DB::transaction(function () use ($values, $by) {
            $matter = new Matter([
                'firm_id' => $by->firm_id,
                'client_id' => $values['client_id'],
                'responsible_lawyer_id' => $values['responsible_lawyer_id'] ?? ($by->role->isLawyer() ? $by->id : null),
                'reference' => $values['reference'],
                'title' => $values['title'],
                'case_type' => $values['case_type'],
                'case_number' => $values['case_number'],
                'court' => $values['court'],
                'court_branch' => $values['court_branch'],
                'judge' => $values['judge'],
                'description' => $values['description'],
                'opened_at' => $values['opened_at'] ?? now()->toDateString(),
            ]);
            $matter->forceFill(['status' => $values['status']])->save();

            $matter->statusEvents()->create([
                'from_status' => null,
                'to_status' => $matter->status,
                'changed_by' => $by->id,
                'reason' => 'Imported from spreadsheet',
            ]);

            foreach ($values['opposing_parties'] as $name) {
                $matter->parties()->create(['role' => PartyRole::AdverseParty, 'name' => mb_substr($name, 0, 255), 'counsel_name' => $values['opposing_counsel']]);
            }
            if ($values['opposing_parties'] === [] && $values['opposing_counsel']) {
                $matter->parties()->create(['role' => PartyRole::AdverseCounsel, 'name' => mb_substr($values['opposing_counsel'], 0, 255)]);
            }

            return $matter;
        });
    }

    public function undo(Model $model, User $by): ?string
    {
        /** @var Matter $model */
        if ($model->timeEntries()->exists() || $model->expenses()->exists() || $model->invoices()->exists()
            || $model->documents()->exists() || $model->files()->exists() || $model->trustAccounts()->exists()
            || $model->deadlines()->exists()) {
            return "{$model->reference} is in use now (deadlines, time, documents, files, invoices or trust).";
        }

        // Nothing was done on it: remove it entirely (the audit log keeps the
        // record), so a corrected file can be imported with the same reference.
        $model->forceDelete();

        return null;
    }
}
