<?php

namespace Tests\Feature\Billing;

use App\Domain\Billing\Models\Expense;
use App\Domain\Billing\Models\TimeEntry;
use App\Domain\Budgets\Notifications\BudgetThresholdReached;
use App\Domain\Matters\Enums\MatterStatus;
use App\Domain\Matters\Models\Client;
use App\Domain\Matters\Models\Firm;
use App\Domain\Matters\Models\Matter;
use App\Domain\Matters\Models\MatterStatusEvent;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class MatterBudgetTest extends TestCase
{
    use RefreshDatabase;

    private Firm $firm;

    private User $lawyer;

    private User $managingPartner;

    private Client $client;

    private Matter $matter;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->firm = Firm::factory()->create();
        $this->managingPartner = User::factory()->create(['firm_id' => $this->firm->id, 'role' => Role::ManagingPartner]);
        $this->lawyer = $this->signIn(Role::Partner, $this->firm);
        $this->client = Client::factory()->for($this->firm)->withPortal('secret-pass')->create();
        $this->matter = Matter::factory()->for($this->client)->create(['responsible_lawyer_id' => $this->lawyer->id, 'status' => MatterStatus::Intake]);
    }

    /** One hour at ₱5,000 = ₱5,000. */
    private function logHours(float $hours, ?string $date = null, bool $billable = true): void
    {
        TimeEntry::create([
            'firm_id' => $this->firm->id, 'matter_id' => $this->matter->id, 'user_id' => $this->lawyer->id,
            'work_date' => $date ?? today()->toDateString(), 'minutes' => (int) ($hours * 60), 'rate_cents' => 500000,
            'description' => 'Work', 'is_billable' => $billable,
        ]);
    }

    public function test_a_peso_budget_tracks_fees_and_expenses_and_alerts_at_80_and_100_percent(): void
    {
        $this->putJson("/api/v1/matters/{$this->matter->id}/budget", ['basis' => 'amount', 'total' => 10_000_00, 'include_expenses' => true])
            ->assertOk()->assertJsonPath('budget.total', 10_000_00)->assertJsonPath('usage.percent', 0);

        $this->logHours(1);                    // ₱5,000: 50%
        $this->logHours(5, billable: false);   // not billable: not counted
        Notification::assertNothingSent();

        Expense::create(['firm_id' => $this->firm->id, 'matter_id' => $this->matter->id, 'user_id' => $this->lawyer->id, 'expense_date' => today()->toDateString(), 'category' => 'filing_fee', 'description' => 'Docket fees', 'amount_cents' => 3_500_00]);
        // ₱8,500: 85%. The responsible lawyer and the managing partner hear about it once.
        Notification::assertSentTo([$this->lawyer, $this->managingPartner], BudgetThresholdReached::class, fn ($n) => $n->threshold === 80 && $n->usage['percent'] === 85);
        Notification::assertSentTimes(BudgetThresholdReached::class, 2);

        $this->logHours(0.1); // still between 80% and 100%: no repeat
        Notification::assertSentTimes(BudgetThresholdReached::class, 2);

        $this->logHours(1); // over
        Notification::assertSentTo($this->lawyer, BudgetThresholdReached::class, fn ($n) => $n->threshold === 100);

        $this->getJson("/api/v1/matters/{$this->matter->id}/budget")
            ->assertJsonPath('usage.fees_cents', 10_500_00)
            ->assertJsonPath('usage.expenses_cents', 3_500_00)
            ->assertJsonPath('usage.used', 14_000_00)
            ->assertJsonPath('usage.percent', 140);

        // Raising the budget re-arms the alerts.
        $this->putJson("/api/v1/matters/{$this->matter->id}/budget", ['basis' => 'amount', 'total' => 50_000_00])
            ->assertJsonPath('budget.alerted_80_at', null)->assertJsonPath('budget.alerted_100_at', null);
    }

    public function test_an_hours_budget_counts_billable_time_by_the_stage_it_was_done_in(): void
    {
        // Filed on the 10th, pre-trial from the 20th.
        $this->travelTo(now()->setDate(2026, 9, 10)->setTime(9, 0));
        MatterStatusEvent::create(['matter_id' => $this->matter->id, 'from_status' => 'intake', 'to_status' => 'filed', 'changed_by' => $this->lawyer->id]);
        $this->travelTo(now()->setDate(2026, 9, 20)->setTime(9, 0));
        MatterStatusEvent::create(['matter_id' => $this->matter->id, 'from_status' => 'filed', 'to_status' => 'pre_trial', 'changed_by' => $this->lawyer->id]);
        $this->travelBack();

        $this->putJson("/api/v1/matters/{$this->matter->id}/budget", [
            'basis' => 'hours', 'total' => 40 * 60,
            'stages' => [['stage' => 'intake', 'total' => 5 * 60], ['stage' => 'filed', 'total' => 10 * 60], ['stage' => 'pre_trial', 'total' => 15 * 60]],
        ])->assertOk()->assertJsonPath('budget.include_expenses', false);

        $this->logHours(3, '2026-09-05');  // intake
        $this->logHours(12, '2026-09-15'); // filed: over its 10 hours
        $this->logHours(2, '2026-09-20');  // pre-trial (changed that morning)

        $usage = $this->getJson("/api/v1/matters/{$this->matter->id}/budget")->assertOk()->json('usage');
        $this->assertSame(17 * 60, $usage['used']);
        $this->assertSame(42, $usage['percent']);
        $byStage = collect($usage['by_stage'])->keyBy('stage');
        $this->assertSame(3 * 60, $byStage['intake']['used']);
        $this->assertSame(120, $byStage['filed']['percent']);
        $this->assertSame(2 * 60, $byStage['pre_trial']['used']);
    }

    public function test_the_stages_cannot_add_up_to_more_than_the_budget(): void
    {
        $this->putJson("/api/v1/matters/{$this->matter->id}/budget", ['basis' => 'amount', 'total' => 1000, 'stages' => [['stage' => 'intake', 'total' => 800], ['stage' => 'trial', 'total' => 300]]])
            ->assertStatus(422)->assertJsonValidationErrors('stages');
        $this->putJson("/api/v1/matters/{$this->matter->id}/budget", ['basis' => 'amount', 'total' => 1000, 'stages' => [['stage' => 'closed', 'total' => 1]]])
            ->assertStatus(422)->assertJsonValidationErrors('stages.0.stage');
    }

    public function test_staff_who_are_not_lawyers_or_finance_cannot_set_a_budget(): void
    {
        $this->signIn(Role::Paralegal, $this->firm);
        $this->putJson("/api/v1/matters/{$this->matter->id}/budget", ['basis' => 'amount', 'total' => 1000])->assertForbidden();
        $this->getJson("/api/v1/matters/{$this->matter->id}/budget")->assertOk()->assertJsonPath('budget', null);
    }

    public function test_the_client_sees_the_budget_only_when_the_firm_shares_it(): void
    {
        $this->putJson("/api/v1/matters/{$this->matter->id}/budget", ['basis' => 'amount', 'total' => 20_000_00, 'include_expenses' => false])->assertOk();
        $this->logHours(1);

        $this->actingAs($this->client, 'client');
        $this->getJson("/api/portal/matters/{$this->matter->id}")->assertOk()->assertJsonPath('budget', null);

        $this->actingAs($this->lawyer, 'web')->actingAs($this->lawyer, 'sanctum');
        $this->putJson("/api/v1/matters/{$this->matter->id}/budget", ['basis' => 'amount', 'total' => 20_000_00, 'include_expenses' => false, 'shared_with_client' => true, 'notes' => 'Internal: client is price-sensitive'])->assertOk();

        $this->actingAs($this->client, 'client');
        $budget = $this->getJson("/api/portal/matters/{$this->matter->id}")->assertOk()->json('budget');
        $this->assertSame(['basis' => 'amount', 'total' => 20_000_00, 'used' => 5_000_00, 'percent' => 25, 'includes_expenses' => false], $budget, 'No notes, no breakdown.');
    }

    public function test_removing_the_budget(): void
    {
        $this->putJson("/api/v1/matters/{$this->matter->id}/budget", ['basis' => 'hours', 'total' => 600])->assertOk();
        $this->deleteJson("/api/v1/matters/{$this->matter->id}/budget")->assertOk()->assertJsonPath('budget', null);
        $this->logHours(20);
        Notification::assertNothingSent();
    }
}
