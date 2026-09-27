<?php

namespace Tests\Feature\Deadlines;

use App\Domain\Deadlines\Models\DeadlineRule;
use App\Domain\Deadlines\Models\HolidayCalendar;
use App\Domain\Deadlines\Models\MatterDeadline;
use App\Domain\Matters\Models\Client;
use App\Domain\Matters\Models\Firm;
use App\Domain\Matters\Models\Matter;
use App\Enums\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeadlineApiTest extends TestCase
{
    use RefreshDatabase;

    private Firm $firm;

    private Matter $matter;

    private DeadlineRule $appeal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->firm = Firm::factory()->create();
        $this->matter = Matter::factory()->for(Client::factory()->for($this->firm))->create();
        $this->appeal = DeadlineRule::create([
            'name' => 'Notice of Appeal', 'trigger_event' => 'Notice of judgment',
            'period_days' => 15, 'period_type' => 'calendar', 'legal_basis' => 'Rule 41, Sec. 3',
        ]);
    }

    public function test_a_rule_based_deadline_computes_its_due_date_and_logs_how(): void
    {
        $this->signIn(Role::Associate, $this->firm);
        // Judgment received Wed 2026-07-01; +15 = Thu 07-16, a holiday -> Fri 07-17.
        HolidayCalendar::create(['date' => '2026-07-16', 'name' => 'Test Holiday', 'type' => 'special_non_working']);

        $response = $this->postJson("/api/v1/matters/{$this->matter->id}/deadlines", [
            'deadline_rule_id' => $this->appeal->id,
            'trigger_date' => '2026-07-01',
        ])->assertCreated()
            ->assertJsonPath('title', 'Notice of Appeal')
            ->assertJsonPath('due_date', '2026-07-17')
            ->assertJsonPath('rule.legal_basis', 'Rule 41, Sec. 3');

        $event = MatterDeadline::find($response->json('id'))->events()->first();
        $this->assertSame('created', $event->event_type);
        $this->assertSame('2026-07-16', $event->payload['computation']['nominal_date']);
        $this->assertSame('Test Holiday', $event->payload['computation']['adjustments'][0]['reason']);
    }

    public function test_another_firms_custom_rule_cannot_be_used(): void
    {
        $this->signIn(Role::Associate, $this->firm);
        $foreign = DeadlineRule::create([
            'firm_id' => Firm::factory()->create()->id, 'name' => 'Private rule',
            'trigger_event' => 'x', 'period_days' => 5, 'period_type' => 'calendar',
        ]);

        $this->postJson("/api/v1/matters/{$this->matter->id}/deadlines", [
            'deadline_rule_id' => $foreign->id, 'trigger_date' => '2026-07-01',
        ])->assertNotFound();
    }

    public function test_hearings_can_be_scheduled_manually_and_appear_on_the_calendar(): void
    {
        $this->signIn(Role::Associate, $this->firm);

        $this->postJson("/api/v1/matters/{$this->matter->id}/deadlines", [
            'kind' => 'hearing', 'title' => 'Pre-trial', 'due_date' => '2026-08-10', 'due_time' => '08:30', 'location' => 'RTC Br. 58',
        ])->assertCreated()->assertJsonPath('due_time', '08:30');

        $this->getJson('/api/v1/deadlines?from=2026-08-01&to=2026-08-31')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.matter.reference', $this->matter->reference);

        $this->getJson('/api/v1/deadlines?from=2026-09-01&to=2026-09-30')->assertJsonCount(0);
    }

    public function test_completing_rescheduling_and_cancelling_are_tracked(): void
    {
        $lawyer = $this->signIn(Role::Associate, $this->firm);
        $deadline = MatterDeadline::factory()->for($this->matter)->create(['due_date' => '2026-08-10']);

        $this->postJson("/api/v1/deadlines/{$deadline->id}/reschedule", ['due_date' => '2026-08-20', 'reason' => 'Motion for extension granted'])
            ->assertOk()->assertJsonPath('due_date', '2026-08-20');

        $this->postJson("/api/v1/deadlines/{$deadline->id}/complete", ['notes' => 'Filed by registered mail'])
            ->assertOk()->assertJsonPath('status', 'completed');

        $this->postJson("/api/v1/deadlines/{$deadline->id}/cancel", ['reason' => 'Too late'])
            ->assertStatus(422);

        $this->assertSame(['completed', 'rescheduled'], $deadline->events()->pluck('event_type')->all());
        $this->assertEquals($lawyer->id, $deadline->fresh()->completed_by);
    }

    public function test_the_compute_endpoint_previews_without_saving(): void
    {
        $this->signIn(Role::Paralegal, $this->firm);

        // Thu 2026-07-09 + 2 = Sat 07-11 -> Mon 07-13.
        $this->postJson('/api/v1/deadlines/compute', ['trigger_date' => '2026-07-09', 'period_days' => 2])
            ->assertOk()
            ->assertJson([
                'due_date' => '2026-07-13',
                'nominal_date' => '2026-07-11',
                'adjustments' => [['date' => '2026-07-11', 'reason' => 'Saturday'], ['date' => '2026-07-12', 'reason' => 'Sunday']],
            ]);

        $this->assertDatabaseCount('matter_deadlines', 0);
    }

    public function test_calendar_range_is_bounded(): void
    {
        $this->signIn(Role::Associate, $this->firm);

        $this->getJson('/api/v1/deadlines?from=2020-01-01&to=2026-01-01')->assertStatus(422);
    }
}
