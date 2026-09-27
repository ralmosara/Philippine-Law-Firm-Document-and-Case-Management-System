<?php

namespace Tests\Feature\Scopes;

use App\Domain\Deadlines\Models\DeadlineRule;
use App\Domain\Matters\Models\Client;
use App\Domain\Matters\Models\Firm;
use App\Domain\Matters\Models\Matter;
use App\Enums\Role;
use App\Support\Tenancy\DatabaseTenancy;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * PostgreSQL row-level security, the second tenancy layer: raw SQL that
 * skips the application's TenantScope still cannot cross firms.
 *
 * Policies never apply to superusers, so each test switches (for its own
 * transaction only) to an ordinary role, as the application runs in
 * production.
 */
class RowLevelSecurityTest extends TestCase
{
    use RefreshDatabase;

    private Firm $firmA;

    private Firm $firmB;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Row-level security is PostgreSQL-specific.');
        }

        if (! DB::scalar('select rolsuper or rolcreaterole from pg_roles where rolname = current_user')) {
            $this->markTestSkipped('The test database user cannot create the probe role.');
        }

        $this->firmA = Firm::factory()->create();
        $this->firmB = Firm::factory()->create();
        Matter::factory()->count(2)->for(Client::factory()->for($this->firmA))->create();
        Matter::factory()->count(3)->for(Client::factory()->for($this->firmB))->create();

        // Created inside the test transaction, so it disappears on rollback.
        DB::unprepared(<<<'SQL'
            CREATE ROLE lexph_rls_probe NOLOGIN;
            GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO lexph_rls_probe;
            GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO lexph_rls_probe;
            SET LOCAL ROLE lexph_rls_probe;
            SQL);
    }

    public function test_every_table_holding_firm_data_has_row_level_security(): void
    {
        // A new table with a firm_id but no policy would be open across firms.
        $unprotected = DB::table('information_schema.columns as col')
            ->join('pg_class as c', 'c.relname', '=', 'col.table_name')
            ->where('col.table_schema', 'public')
            ->where('col.column_name', 'firm_id')
            ->whereRaw("c.relnamespace = 'public'::regnamespace")
            ->whereRaw('not (c.relrowsecurity and c.relforcerowsecurity)')
            ->pluck('col.table_name')
            ->all();

        $this->assertSame([], $unprotected, 'Call RowLevelSecurity::enable() in the migration for: '.implode(', ', $unprotected));
    }

    public function test_a_restricted_connection_only_sees_its_own_firm(): void
    {
        app(DatabaseTenancy::class)->restrictTo($this->firmA->id);

        $this->assertSame([$this->firmA->id], DB::table('matters')->distinct()->pluck('firm_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame(1, DB::table('clients')->count());
        $this->assertSame(0, DB::table('matters')->where('firm_id', $this->firmB->id)->count());
    }

    public function test_writing_into_another_firm_is_rejected(): void
    {
        app(DatabaseTenancy::class)->restrictTo($this->firmA->id);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('row-level security');

        DB::table('clients')->insert([
            'firm_id' => $this->firmB->id, 'type' => 'individual', 'name' => 'Planted', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_rows_cannot_be_moved_to_another_firm(): void
    {
        app(DatabaseTenancy::class)->restrictTo($this->firmA->id);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('row-level security');

        DB::table('matters')->update(['firm_id' => $this->firmB->id]);
    }

    public function test_a_connection_with_no_tenant_setting_sees_nothing(): void
    {
        DB::select("select set_config('app.firm_id', '', false), set_config('app.rls_bypass', '', false)");

        $this->assertSame(0, DB::table('matters')->count());
        $this->assertSame(0, DB::table('users')->count());
    }

    public function test_trusted_system_mode_sees_every_firm(): void
    {
        app(DatabaseTenancy::class)->bypass();

        $this->assertSame(5, DB::table('matters')->count());
    }

    public function test_shared_deadline_rules_are_visible_to_every_firm(): void
    {
        app(DatabaseTenancy::class)->bypass();
        DeadlineRule::create(['name' => 'Rules of Court', 'trigger_event' => 'x', 'period_days' => 15, 'period_type' => 'calendar']);
        DeadlineRule::create(['firm_id' => $this->firmB->id, 'name' => 'Firm B only', 'trigger_event' => 'x', 'period_days' => 5, 'period_type' => 'calendar']);

        app(DatabaseTenancy::class)->restrictTo($this->firmA->id);

        $this->assertSame(['Rules of Court'], DB::table('deadline_rules')->pluck('name')->all());
    }

    public function test_an_authenticated_request_is_restricted_even_for_raw_queries(): void
    {
        Route::middleware(['api', 'auth:sanctum', 'tenant'])
            ->get('/api/v1/_rls-probe', fn () => ['firm_ids' => DB::table('matters')->distinct()->pluck('firm_id')->map(fn ($id) => (int) $id)]);

        $this->signIn(Role::Associate, $this->firmA);

        $this->getJson('/api/v1/_rls-probe')->assertOk()->assertJsonPath('firm_ids', [$this->firmA->id]);

        // Back in trusted mode after the request.
        $this->assertSame(5, DB::table('matters')->count());
    }
}
