<?php

namespace App\Domain\Imports\Importers;

use App\Domain\Imports\Values;
use App\Domain\Matters\Models\Client;
use App\Domain\Matters\Models\Matter;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * One kind of record that can be imported from a spreadsheet: which columns
 * it takes, how a row is checked (errors, duplicates) and created, and how
 * an import is taken back.
 */
abstract class Importer
{
    /** @var array<string, true> duplicate keys seen earlier in the same file */
    protected array $seen = [];

    protected int $firmId;

    abstract public function label(): string;

    /** The gate that may run this import. */
    abstract public function ability(): string;

    /** @return class-string<Model> */
    abstract public function modelClass(): string;

    /**
     * @return array<string, array{label: string, required?: bool, aliases?: list<string>, example: string, hint?: string}>
     */
    abstract public function columns(): array;

    /**
     * @param  array<string, string>  $row  column key => raw cell text
     * @return array{values: array<string, mixed>, errors: list<string>, warnings: list<string>, duplicate: ?string}
     */
    abstract public function check(array $row): array;

    /** @param  array<string, mixed>  $values  as returned by check() */
    abstract public function create(array $values, User $by): Model;

    /** Takes the record back. Returns null when done, or why it cannot be. */
    abstract public function undo(Model $model, User $by): ?string;

    /** Load what check() compares against. Called once per file. */
    public function prepare(int $firmId): void
    {
        $this->firmId = $firmId;
        $this->seen = [];
    }

    /**
     * Map spreadsheet headers to column keys by name or alias.
     *
     * @param  list<string>  $headers
     * @return array{map: array<string, string>, missing: list<string>, ignored: list<string>}
     */
    public function mapHeaders(array $headers): array
    {
        $lookup = [];
        foreach ($this->columns() as $key => $column) {
            foreach ([$key, $column['label'], ...($column['aliases'] ?? [])] as $name) {
                $lookup[Values::headerKey($name)] = $key;
            }
        }

        $map = [];
        $ignored = [];
        foreach ($headers as $header) {
            $key = $lookup[Values::headerKey($header)] ?? null;
            if ($key !== null && ! in_array($key, $map, true)) {
                $map[$header] = $key;
            } else {
                $ignored[] = $header;
            }
        }

        $missing = array_values(array_map(
            fn ($key) => $this->columns()[$key]['label'],
            array_keys(array_filter($this->columns(), fn ($c, $key) => ($c['required'] ?? false) && ! in_array($key, $map, true), ARRAY_FILTER_USE_BOTH)),
        ));

        return ['map' => $map, 'missing' => $missing, 'ignored' => $ignored];
    }

    /** First time a key is seen in this file: false; again: true. */
    protected function seenInFile(string $key): bool
    {
        if (isset($this->seen[$key])) {
            return true;
        }
        $this->seen[$key] = true;

        return false;
    }

    /** @var Collection<int, Client>|null */
    private ?Collection $clients = null;

    /** @var Collection<int, User>|null */
    private ?Collection $users = null;

    /** @var Collection<int, Matter>|null */
    private ?Collection $matters = null;

    /** @return Collection<int, Client> */
    protected function clients(): Collection
    {
        return $this->clients ??= Client::query()->get(['id', 'name', 'email', 'tin', 'type']);
    }

    protected function forgetClients(): void
    {
        $this->clients = null;
    }

    /** By e-mail, else by name (ignoring case, punctuation and "Inc."/"Corp."). */
    protected function findClient(string $value, ?string &$error): ?Client
    {
        $value = trim($value);
        if (str_contains($value, '@')) {
            $client = $this->clients()->first(fn (Client $c) => strcasecmp((string) $c->email, $value) === 0);
            $error = $client ? null : "No client with e-mail {$value}. Import clients first.";

            return $client;
        }

        $matches = $this->clients()->filter(fn (Client $c) => Values::nameKey($c->name) === Values::nameKey($value));
        $error = match ($matches->count()) {
            0 => "No client named \"{$value}\". Import clients first, or check the spelling.",
            1 => null,
            default => "More than one client is named \"{$value}\"; use the client's e-mail instead.",
        };

        return $matches->count() === 1 ? $matches->first() : null;
    }

    /** @return Collection<int, Matter> */
    protected function matters(): Collection
    {
        return $this->matters ??= Matter::query()->get(['id', 'client_id', 'reference', 'case_number', 'title', 'responsible_lawyer_id']);
    }

    protected function forgetMatters(): void
    {
        $this->matters = null;
    }

    /** By the firm's reference (M-2026-0042 or a legacy file number), else by docket number. */
    protected function findMatter(string $value, ?string &$error): ?Matter
    {
        $value = trim($value);
        $matter = $this->matters()->first(fn (Matter $m) => strcasecmp($m->reference, $value) === 0)
            ?? $this->matters()->first(fn (Matter $m) => $m->case_number !== null && self::docketKey($m->case_number) === self::docketKey($value));
        $error = $matter ? null : "No matter with reference or case number \"{$value}\". Import matters first.";

        return $matter;
    }

    public static function docketKey(string $caseNumber): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $caseNumber));
    }

    /** An active member of the firm, by e-mail or full name. */
    protected function findUser(string $value, ?string &$error): ?User
    {
        $value = trim($value);
        $this->users ??= User::query()->where('is_active', true)->get(['id', 'name', 'email', 'role']);

        $user = $this->users->first(fn (User $u) => strcasecmp($u->email, $value) === 0)
            ?? $this->users->first(fn (User $u) => Values::nameKey($u->name) === Values::nameKey($value));
        $error = $user ? null : "No active user \"{$value}\". Use their e-mail as it appears in Firm Settings → Users.";

        return $user;
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  list<string>  $errors
     * @param  list<string>  $warnings
     * @return array{values: array<string, mixed>, errors: list<string>, warnings: list<string>, duplicate: ?string}
     */
    protected function result(array $values, array $errors = [], ?string $duplicate = null, array $warnings = []): array
    {
        return ['values' => $values, 'errors' => $errors, 'warnings' => $warnings, 'duplicate' => $duplicate];
    }
}
