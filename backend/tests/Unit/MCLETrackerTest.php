<?php

namespace Tests\Unit;

use App\Domain\Compliance\Models\McleCompliancePeriod;
use App\Domain\Compliance\Models\McleCredit;
use App\Domain\Compliance\Services\MCLETracker;
use App\Domain\Matters\Models\Firm;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MCLETrackerTest extends TestCase
{
    use RefreshDatabase;

    private McleCompliancePeriod $period;

    protected function setUp(): void
    {
        parent::setUp();
        $this->period = McleCompliancePeriod::create([
            'name' => 'Compliance Period 2025–2028', 'start_date' => '2025-04-15', 'end_date' => '2028-04-14', 'required_units' => 36,
        ]);
    }

    private function credit(User $user, float $units): void
    {
        McleCredit::create([
            'user_id' => $user->id, 'period_id' => $this->period->id, 'title' => 'Seminar',
            'units' => $units, 'date_earned' => '2026-01-10',
        ]);
    }

    public function test_it_calculates_mcle_compliance_status(): void
    {
        $user = User::factory()->create();
        $this->credit($user, 10);
        $this->credit($user, 20);
        $tracker = new MCLETracker;

        $status = $tracker->getComplianceStatus($user->id, $this->period->id);
        $this->assertEquals(30, $status['earned_units']);
        $this->assertEquals(6, $status['remaining_units']);
        $this->assertSame(83, $status['percent']);
        $this->assertFalse($status['is_compliant']);

        $this->credit($user, 10.5);

        $status = $tracker->getComplianceStatus($user->id, $this->period->id);
        $this->assertEquals(40.5, $status['earned_units']);
        $this->assertEquals(0, $status['remaining_units']);
        $this->assertSame(100, $status['percent']);
        $this->assertTrue($status['is_compliant']);
    }

    public function test_the_firm_summary_covers_active_lawyers_least_compliant_first(): void
    {
        $firm = Firm::factory()->create();
        $ahead = User::factory()->create(['firm_id' => $firm->id, 'name' => 'Ahead']);
        $behind = User::factory()->create(['firm_id' => $firm->id, 'name' => 'Behind']);
        User::factory()->paralegal()->create(['firm_id' => $firm->id]);
        User::factory()->create(['firm_id' => $firm->id, 'is_active' => false]);
        User::factory()->create(); // another firm
        $this->credit($ahead, 30);
        $this->credit($behind, 6);

        $summary = (new MCLETracker)->firmSummary($firm->id, $this->period);

        $this->assertSame(['Behind', 'Ahead'], $summary->pluck('name')->all());
    }

    public function test_the_current_period_is_found_by_date(): void
    {
        $this->assertSame($this->period->id, McleCompliancePeriod::current(new \DateTimeImmutable('2026-06-01'))?->id);
        $this->assertNull(McleCompliancePeriod::current(new \DateTimeImmutable('2030-01-01')));
    }

    public function test_lawyers_log_their_own_credits_via_the_api(): void
    {
        $lawyer = $this->signIn(Role::Associate);
        $colleague = User::factory()->create(['firm_id' => $lawyer->firm_id]);

        $this->postJson('/api/v1/mcle/credits', [
            'period_id' => $this->period->id, 'title' => 'Legal Ethics', 'units' => 6, 'date_earned' => '2026-02-01',
        ])->assertCreated();

        $this->postJson('/api/v1/mcle/credits', [
            'user_id' => $colleague->id, 'period_id' => $this->period->id, 'title' => 'Not mine', 'units' => 6, 'date_earned' => '2026-02-01',
        ])->assertForbidden();

        $this->getJson("/api/v1/mcle/status?period_id={$this->period->id}")
            ->assertOk()
            ->assertJsonPath('status.earned_units', 6)
            ->assertJsonCount(1, 'credits');
    }
}
