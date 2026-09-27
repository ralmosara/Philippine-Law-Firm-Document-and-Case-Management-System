<?php

namespace App\Support\Tenancy;

use Illuminate\Support\Facades\DB;

/**
 * Applies the tenant isolation policy (see the enable_row_level_security
 * migration) to firm-owned tables created by later migrations.
 */
class RowLevelSecurity
{
    public static function enable(string ...$tables): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $policy = "current_setting('app.rls_bypass', true) = 'on' OR firm_id = nullif(current_setting('app.firm_id', true), '')::bigint";

        foreach ($tables as $table) {
            DB::unprepared(<<<SQL
                ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY;
                ALTER TABLE {$table} FORCE ROW LEVEL SECURITY;
                CREATE POLICY tenant_isolation ON {$table} USING ({$policy}) WITH CHECK ({$policy});
                SQL);
        }
    }

    public static function disable(string ...$tables): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($tables as $table) {
            DB::unprepared("DROP POLICY IF EXISTS tenant_isolation ON {$table}; ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY; ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY;");
        }
    }
}
