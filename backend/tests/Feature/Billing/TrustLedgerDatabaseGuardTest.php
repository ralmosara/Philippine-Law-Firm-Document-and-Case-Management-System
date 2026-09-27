<?php

namespace Tests\Feature\Billing;

use App\Domain\Matters\Models\Client;
use App\Domain\Trust\Models\TrustAccount;
use App\Domain\Trust\Services\TrustLedgerService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The trust ledger's database-level defences (PostgreSQL only): even a raw
 * query that bypasses the application cannot rewrite history or break the
 * running balance.
 */
class TrustLedgerDatabaseGuardTest extends TestCase
{
    use RefreshDatabase;

    private TrustAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Database-level ledger guards are PostgreSQL-specific.');
        }

        $this->account = TrustAccount::factory()->for(Client::factory())->create();
        app(TrustLedgerService::class)->deposit($this->account, 10_000, 'Deposit');
    }

    public function test_ledger_rows_cannot_be_updated_with_raw_sql(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('append-only');

        DB::table('trust_transactions')->update(['amount_cents' => 999_999]);
    }

    public function test_ledger_rows_cannot_be_deleted_with_raw_sql(): void
    {
        $this->expectException(QueryException::class);

        DB::table('trust_transactions')->delete();
    }

    public function test_an_insert_with_a_wrong_running_balance_is_rejected(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('continuity');

        DB::table('trust_transactions')->insert([
            'trust_account_id' => $this->account->id,
            'type' => 'deposit',
            'amount_cents' => 500,
            'balance_after_cents' => 1_000_000, // should be 10,500
            'description' => 'Forged',
        ]);
    }

    public function test_an_overdraw_is_rejected_by_the_check_constraint(): void
    {
        $this->expectException(QueryException::class);

        DB::table('trust_transactions')->insert([
            'trust_account_id' => $this->account->id,
            'type' => 'disbursement',
            'amount_cents' => 20_000,
            'balance_after_cents' => -10_000,
            'description' => 'Overdraw',
        ]);
    }
}
