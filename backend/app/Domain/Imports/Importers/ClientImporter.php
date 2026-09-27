<?php

namespace App\Domain\Imports\Importers;

use App\Domain\Imports\Values;
use App\Domain\Matters\Models\Client;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class ClientImporter extends Importer
{
    public function label(): string
    {
        return 'Clients';
    }

    public function ability(): string
    {
        return 'manage-firm';
    }

    public function modelClass(): string
    {
        return Client::class;
    }

    public function columns(): array
    {
        return [
            'name' => ['label' => 'Name', 'required' => true, 'aliases' => ['client', 'client name', 'full name', 'company', 'company name', 'name of client'], 'example' => 'Luzon Logistics & Freight Corp.'],
            'type' => ['label' => 'Type', 'aliases' => ['client type', 'kind'], 'example' => 'corporate', 'hint' => 'individual or corporate. Left blank: corporate when the name ends in Inc., Corp., Co. and the like.'],
            'email' => ['label' => 'Email', 'aliases' => ['e-mail', 'email address', 'e-mail address'], 'example' => 'legal@luzonlogistics.ph'],
            'phone' => ['label' => 'Phone', 'aliases' => ['mobile', 'mobile number', 'contact number', 'contact no', 'telephone', 'tel', 'cellphone'], 'example' => '+63 917 555 0101'],
            'tin' => ['label' => 'TIN', 'aliases' => ['tax id', 'tax identification number'], 'example' => '123-456-789-000'],
            'address' => ['label' => 'Address', 'aliases' => ['business address', 'home address'], 'example' => 'Pier 4, North Harbor, Tondo, Manila'],
            'notes' => ['label' => 'Notes', 'aliases' => ['remarks', 'comments'], 'example' => 'Referred by Atty. Reyes'],
        ];
    }

    public function check(array $row): array
    {
        $errors = [];
        $name = trim($row['name'] ?? '');
        $email = mb_strtolower(trim($row['email'] ?? ''));
        $tin = trim($row['tin'] ?? '');
        $typeText = mb_strtolower(trim($row['type'] ?? ''));

        if ($name === '') {
            $errors[] = 'Name is required.';
        } elseif (mb_strlen($name) > 255) {
            $errors[] = 'Name is longer than 255 characters.';
        }

        if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "\"{$row['email']}\" is not a valid e-mail address.";
        }
        if ($tin !== '' && ! preg_match('/^\d{3}-?\d{3}-?\d{3}(-?\d{3,5})?$/', $tin)) {
            $errors[] = "TIN \"{$tin}\" should look like 123-456-789-000.";
        }

        $type = match (true) {
            in_array($typeText, ['individual', 'person', 'natural person', 'natural', 'i'], true) => 'individual',
            in_array($typeText, ['corporate', 'corporation', 'company', 'juridical', 'business', 'c'], true) => 'corporate',
            $typeText === '' => preg_match('/\b(inc|incorporated|corp|corporation|co|company|ltd|opc|llc|partnership|cooperative|foundation)\b\.?\s*$/i', $name) ? 'corporate' : 'individual',
            default => null,
        };
        if ($type === null) {
            $errors[] = "Type \"{$row['type']}\" should be individual or corporate.";
        }

        $values = [
            'name' => $name,
            'type' => $type,
            'email' => $email ?: null,
            'phone' => mb_substr(trim($row['phone'] ?? ''), 0, 30) ?: null,
            'tin' => $tin ?: null,
            'address' => mb_substr(trim($row['address'] ?? ''), 0, 255) ?: null,
            'notes' => trim($row['notes'] ?? '') ?: null,
        ];

        if ($errors !== []) {
            return $this->result($values, $errors);
        }

        return $this->result($values, duplicate: $this->duplicate($values));
    }

    private function duplicate(array $values): ?string
    {
        $existing = $this->clients()->first(fn (Client $c) => ($values['email'] && strcasecmp((string) $c->email, $values['email']) === 0)
            || ($values['tin'] && $c->tin && Values::tinKey($c->tin) === Values::tinKey($values['tin']))
            || Values::nameKey($c->name) === Values::nameKey($values['name']));

        if ($existing) {
            return 'Already a client: '.rtrim($existing->name, '.').'.';
        }

        $keys = array_filter(['n:'.Values::nameKey($values['name']), $values['email'] ? 'e:'.$values['email'] : null, $values['tin'] ? 't:'.Values::tinKey($values['tin']) : null]);
        $repeated = false;
        foreach ($keys as $key) {
            $repeated = $this->seenInFile($key) || $repeated; // record every key, not just the first hit
        }

        return $repeated ? 'The same client appears earlier in this file.' : null;
    }

    public function create(array $values, User $by): Model
    {
        return Client::create(['firm_id' => $by->firm_id, ...$values]);
    }

    public function undo(Model $model, User $by): ?string
    {
        /** @var Client $model */
        if ($model->matters()->exists() || $model->trustAccounts()->exists() || $model->invoices()->exists()) {
            return "{$model->name} now has matters, trust accounts or invoices.";
        }

        // Never used: remove it entirely (the audit log keeps the record), so a
        // corrected file can bring the same e-mail back.
        $model->forceDelete();

        return null;
    }
}
