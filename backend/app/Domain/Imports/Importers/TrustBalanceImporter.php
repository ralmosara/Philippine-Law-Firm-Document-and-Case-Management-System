<?php

namespace App\Domain\Imports\Importers;

use App\Domain\Imports\Values;
use App\Domain\Trust\Models\TrustAccount;
use App\Domain\Trust\Services\TrustLedgerService;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Client funds the firm already holds, brought over from the old books:
 * one new trust account per row, opened with a deposit that says where the
 * balance came from. The ledger stays append-only; undo posts a reversal.
 */
class TrustBalanceImporter extends Importer
{
    public function label(): string
    {
        return 'Trust opening balances';
    }

    public function ability(): string
    {
        return 'manage-finances';
    }

    public function modelClass(): string
    {
        return TrustAccount::class;
    }

    public function columns(): array
    {
        return [
            'client' => ['label' => 'Client', 'required' => true, 'aliases' => ['client name', 'client email'], 'example' => 'Luzon Logistics & Freight Corp.'],
            'matter' => ['label' => 'Matter', 'aliases' => ['matter reference', 'reference', 'case number'], 'example' => 'M-2026-0001', 'hint' => 'Optional: tie the account to one of the client’s matters.'],
            'balance' => ['label' => 'Opening balance', 'required' => true, 'aliases' => ['balance', 'amount', 'opening balance php', 'trust balance'], 'example' => '150,000.00', 'hint' => 'In pesos, as in your trust ledger or bank statement.'],
            'as_of' => ['label' => 'As of', 'aliases' => ['date', 'balance date', 'as of date'], 'example' => '09/30/2026', 'hint' => 'The date of the balance. Blank: today.'],
            'reference' => ['label' => 'Reference', 'aliases' => ['bank reference', 'ledger reference', 'old account no'], 'example' => 'Old ledger p. 14'],
        ];
    }

    public function check(array $row): array
    {
        $errors = [];

        $client = null;
        if (trim($row['client'] ?? '') === '') {
            $errors[] = 'Client is required.';
        } else {
            $client = $this->findClient($row['client'], $error);
            if ($error) {
                $errors[] = $error;
            }
        }

        $matter = null;
        if (trim($row['matter'] ?? '') !== '') {
            $matter = $this->findMatter($row['matter'], $error);
            if ($error) {
                $errors[] = $error;
            } elseif ($client && $matter->client_id !== $client->id) {
                $errors[] = "{$matter->reference} is not a matter of {$client->name}.";
            }
        }

        $cents = Values::cents($row['balance'] ?? '');
        if (trim($row['balance'] ?? '') === '') {
            $errors[] = 'Opening balance is required.';
        } elseif ($cents === null) {
            $errors[] = "Opening balance \"{$row['balance']}\" is not an amount.";
        } elseif ($cents <= 0) {
            $errors[] = 'Opening balance must be more than zero; a zero balance needs no account yet.';
        } elseif ($cents > 2_000_000_000) {
            $errors[] = 'Opening balance is too large for one entry.';
        }

        $asOf = CarbonImmutable::today();
        if (trim($row['as_of'] ?? '') !== '') {
            $asOf = Values::date($row['as_of']);
            if ($asOf === null) {
                $errors[] = "As of \"{$row['as_of']}\" is not a date.";
            } elseif ($asOf->isFuture()) {
                $errors[] = 'As of is in the future.';
            }
        }

        $values = [
            'client_id' => $client?->id,
            'client_name' => $client?->name,
            'matter_id' => $matter?->id,
            'matter_reference' => $matter?->reference,
            'balance_cents' => $cents,
            'as_of' => $asOf?->toDateString(),
            'reference' => mb_substr(trim($row['reference'] ?? ''), 0, 64) ?: null,
        ];

        if ($errors !== []) {
            return $this->result($values, $errors);
        }

        $open = TrustAccount::query()->where('client_id', $client->id)->where('status', 'open')
            ->where(fn ($q) => $matter ? $q->where('matter_id', $matter->id) : $q->whereNull('matter_id'))
            ->value('account_number');
        $duplicate = match (true) {
            $open !== null => "{$client->name} already has trust account {$open}; record a deposit there instead.",
            $this->seenInFile($client->id.'|'.($matter?->id ?? '')) => 'The same client and matter appear earlier in this file.',
            default => null,
        };

        return $this->result($values, duplicate: $duplicate);
    }

    public function create(array $values, User $by): Model
    {
        return DB::transaction(function () use ($values, $by) {
            $account = TrustAccount::create(['firm_id' => $by->firm_id, 'client_id' => $values['client_id'], 'matter_id' => $values['matter_id']]);
            $asOf = CarbonImmutable::parse($values['as_of'])->format('F j, Y');
            app(TrustLedgerService::class)->deposit($account, $values['balance_cents'], "Opening balance brought forward (as of {$asOf})", $values['reference'], $by);

            return $account->refresh();
        });
    }

    public function undo(Model $model, User $by): ?string
    {
        /** @var TrustAccount $model */
        if ($model->transactions()->count() > 1 || $model->status !== 'open') {
            return "{$model->account_number} has had other transactions since the import.";
        }

        $ledger = app(TrustLedgerService::class);
        $ledger->disburse($model, $model->balance_cents, 'Reversal of imported opening balance (import undone)', null, $by);
        $ledger->close($model->refresh());

        return null;
    }
}
