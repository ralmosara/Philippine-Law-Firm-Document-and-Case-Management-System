<?php

namespace Tests\Unit;

use Tests\TestCase; // Must use Laravel's TestCase to access DB
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Domain\Trust\Services\TrustLedgerService;
use App\Models\Matter;
use Illuminate\Support\Facades\DB;
use Exception;

class TrustLedgerServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_can_deposit_and_update_balance()
    {
        $firmId = DB::table('firms')->insertGetId(['name' => 'Test Firm', 'created_at' => now(), 'updated_at' => now()]);
        $clientId = DB::table('clients')->insertGetId(['firm_id' => $firmId, 'name' => 'Test Client', 'created_at' => now(), 'updated_at' => now()]);
        $matterId = DB::table('matters')->insertGetId(['firm_id' => $firmId, 'client_id' => $clientId, 'case_number' => 'TEST-001', 'created_at' => now(), 'updated_at' => now()]);

        $trustAccountId = DB::table('trust_accounts')->insertGetId([
            'matter_id' => $matterId,
            'current_balance_cents' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service = new TrustLedgerService();
        $service->deposit($trustAccountId, 50000); // 500 PHP

        $balance = DB::table('trust_accounts')->where('id', $trustAccountId)->value('current_balance_cents');
        $this->assertEquals(50000, $balance);
    }

    public function test_it_prevents_overdrawing_trust_account()
    {
        $firmId = DB::table('firms')->insertGetId(['name' => 'Test Firm', 'created_at' => now(), 'updated_at' => now()]);
        $clientId = DB::table('clients')->insertGetId(['firm_id' => $firmId, 'name' => 'Test Client', 'created_at' => now(), 'updated_at' => now()]);
        $matterId = DB::table('matters')->insertGetId(['firm_id' => $firmId, 'client_id' => $clientId, 'case_number' => 'TEST-001', 'created_at' => now(), 'updated_at' => now()]);

        $trustAccountId = DB::table('trust_accounts')->insertGetId([
            'matter_id' => $matterId,
            'current_balance_cents' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service = new TrustLedgerService();
        $service->deposit($trustAccountId, 10000); // 100 PHP

        $this->expectException(Exception::class);
        $this->expectExceptionMessage("Insufficient trust funds");
        
        $service->withdraw($trustAccountId, 20000); // Try to withdraw 200 PHP
    }
}
