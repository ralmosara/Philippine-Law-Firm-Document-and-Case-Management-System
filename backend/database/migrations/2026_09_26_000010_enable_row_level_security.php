<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PostgreSQL row-level security: a second tenancy layer beneath the
 * application's TenantScope.
 *
 * Each firm-owned table only exposes rows whose firm_id matches the
 * `app.firm_id` setting, which SetTenantContext sets for every
 * authenticated request (see App\Support\Tenancy\DatabaseTenancy). Trusted
 * system code (sign-in, webhooks, queue workers, the scheduler, migrations)
 * runs with `app.rls_bypass = on`. A connection with neither setting sees
 * nothing, so the policy fails closed.
 *
 * FORCE makes the policies apply to the table owner too. They never apply to
 * superusers or BYPASSRLS roles, so the application must connect as an
 * ordinary role (docker-compose.yml does).
 */
return new class extends Migration
{
    /** Tables whose rows all belong to one firm. */
    private const TENANT_TABLES = [
        'users', 'clients', 'matters', 'matter_deadlines', 'document_templates', 'documents',
        'notarial_entries', 'trust_accounts', 'time_entries', 'invoices', 'conflict_checks',
        'audit_logs', 'case_workflow_templates', 'matter_files', 'signature_requests', 'payments',
    ];

    /** Tables that also hold shared, firm-less rows every firm may read. */
    private const SHARED_TABLES = ['deadline_rules'];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $bypass = "current_setting('app.rls_bypass', true) = 'on'";
        $ownFirm = "firm_id = nullif(current_setting('app.firm_id', true), '')::bigint";

        foreach ([...self::TENANT_TABLES, ...self::SHARED_TABLES] as $table) {
            $visible = in_array($table, self::SHARED_TABLES, true)
                ? "{$bypass} OR {$ownFirm} OR firm_id IS NULL"
                : "{$bypass} OR {$ownFirm}";

            DB::unprepared(<<<SQL
                ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY;
                ALTER TABLE {$table} FORCE ROW LEVEL SECURITY;
                CREATE POLICY tenant_isolation ON {$table}
                    USING ({$visible})
                    WITH CHECK ({$bypass} OR {$ownFirm});
                SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach ([...self::TENANT_TABLES, ...self::SHARED_TABLES] as $table) {
            DB::unprepared(<<<SQL
                DROP POLICY IF EXISTS tenant_isolation ON {$table};
                ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY;
                ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY;
                SQL);
        }
    }
};
