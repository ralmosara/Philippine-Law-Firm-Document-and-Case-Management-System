<?php

namespace App\Support\Tenancy;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use PDO;

/**
 * Drives PostgreSQL row-level security (see the enable_row_level_security
 * migration) from the application's tenant context.
 *
 * - restrictTo(): the connection only sees and writes the given firm's rows.
 * - bypass():     trusted system code (sign-in, webhooks, workers) sees all.
 *
 * On other databases these are no-ops and isolation rests on TenantScope.
 */
class DatabaseTenancy
{
    public function restrictTo(int $firmId, ?Connection $connection = null): void
    {
        $this->apply($connection ?? DB::connection(), $firmId);
    }

    public function bypass(?Connection $connection = null): void
    {
        $this->apply($connection ?? DB::connection(), null);
    }

    /**
     * Put a newly created connection into the given mode as soon as it
     * actually connects. Laravel opens the socket lazily, so this does not
     * force a connection for code that never queries the database.
     */
    public function initialize(Connection $connection, ?int $firmId): void
    {
        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        $raw = $connection->getRawPdo();

        if ($raw instanceof Closure) {
            $connection->setPdo(function () use ($raw, $firmId) {
                $pdo = $raw();
                $pdo->exec($this->statement($firmId, $pdo));

                return $pdo;
            });
        } elseif ($raw instanceof PDO) {
            $this->apply($connection, $firmId);
        }
    }

    private function apply(Connection $connection, ?int $firmId): void
    {
        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        $connection->getPdo()->exec($this->statement($firmId, $connection->getPdo()));
    }

    /**
     * Session-level settings (is_local = false), so they hold for the whole
     * request, including work done outside explicit transactions.
     */
    private function statement(?int $firmId, PDO $pdo): string
    {
        $firm = $pdo->quote($firmId === null ? '' : (string) $firmId);
        $bypass = $pdo->quote($firmId === null ? 'on' : 'off');

        return "select set_config('app.firm_id', {$firm}, false), set_config('app.rls_bypass', {$bypass}, false)";
    }
}
