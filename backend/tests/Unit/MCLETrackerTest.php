<?php

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Domain\Compliance\Services\MCLETracker;
use Illuminate\Support\Facades\DB;

class MCLETrackerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_calculates_mcle_compliance_status()
    {
        // 1. Create a compliance period
        $periodId = DB::table('mcle_compliance_periods')->insertGetId([
            'name' => '8th Compliance Period',
            'start_date' => '2025-04-15',
            'end_date' => '2028-04-14',
            'required_units' => 36,
            'created_at' => now(),
            'updated_at' => now()
        ]);
        
        // 2. Create a user (lawyer)
        $userId = DB::table('users')->insertGetId([
            'name' => 'Atty. Test',
            'email' => 'test@lawfirm.com',
            'password' => bcrypt('password'),
            'created_at' => now(),
            'updated_at' => now()
        ]);
        
        // 3. Add credits
        DB::table('mcle_credits')->insert([
            ['user_id' => $userId, 'period_id' => $periodId, 'title' => 'Ethics Seminar', 'units_earned' => 10, 'date_earned' => '2026-01-10', 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => $userId, 'period_id' => $periodId, 'title' => 'Trial Advocacy', 'units_earned' => 20, 'date_earned' => '2026-05-15', 'created_at' => now(), 'updated_at' => now()]
        ]);
        
        $tracker = new MCLETracker();
        $status = $tracker->getComplianceStatus($userId, $periodId);
        
        $this->assertEquals(30, $status['earned_units']);
        $this->assertEquals(6, $status['remaining_units']);
        $this->assertFalse($status['is_compliant']);
        
        // Add more credits to meet compliance
        DB::table('mcle_credits')->insert([
            'user_id' => $userId, 'period_id' => $periodId, 'title' => 'Legal Writing', 'units_earned' => 10, 'date_earned' => '2026-08-01', 'created_at' => now(), 'updated_at' => now()
        ]);
        
        $status = $tracker->getComplianceStatus($userId, $periodId);
        $this->assertEquals(40, $status['earned_units']);
        $this->assertEquals(0, $status['remaining_units']);
        $this->assertTrue($status['is_compliant']);
    }
}
