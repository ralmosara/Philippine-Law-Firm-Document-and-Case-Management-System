<?php

namespace Tests\Feature\Deadlines;

use App\Domain\Deadlines\Models\HolidayCalendar;
use App\Domain\Matters\Models\Client;
use App\Domain\Matters\Models\Firm;
use App\Domain\Matters\Models\Matter;
use App\Domain\Prescription\Notifications\PrescriptionApproaching;
use App\Enums\Role;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PrescriptionTest extends TestCase
{
    use RefreshDatabase;

    private Firm $firm;

    private User $lawyer;

    private User $managingPartner;

    private Matter $matter;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-05 09:00:00');
        $this->firm = Firm::factory()->create();
        $this->managingPartner = User::factory()->create(['firm_id' => $this->firm->id, 'role' => Role::ManagingPartner]);
        $this->lawyer = $this->signIn(Role::Partner, $this->firm);
        $this->matter = Matter::factory()->for(Client::factory()->for($this->firm))->create(['responsible_lawyer_id' => $this->lawyer->id]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_written_contract_prescribes_ten_calendar_years_after_accrual(): void
    {
        $this->getJson('/api/v1/prescription-periods')->assertOk()->assertJsonPath('groups.0.periods.0.key', 'written_contract');

        // 10 years from March 4, 2017 ends Thursday, March 4, 2027.
        $this->postJson("/api/v1/matters/{$this->matter->id}/prescriptions", ['period_key' => 'written_contract', 'accrued_on' => '2017-03-04'])
            ->assertCreated()
            ->assertJsonPath('label', 'Action upon a written contract')
            ->assertJsonPath('basis', 'Civil Code, Art. 1144(1)')
            ->assertJsonPath('last_day', '2027-03-04')
            ->assertJsonPath('file_by', '2027-03-04')
            ->assertJsonPath('state', 'soon')
            ->assertJsonPath('days_left', 150);
    }

    public function test_a_last_day_on_a_weekend_or_holiday_moves_to_the_next_working_day(): void
    {
        HolidayCalendar::create(['date' => '2027-12-30', 'name' => 'Rizal Day']);

        // 4 years from Dec 30, 2023 → Thursday, Dec 30, 2027 (a holiday) → Friday Dec 31.
        $this->postJson('/api/v1/prescription-periods/preview', ['from' => '2023-12-30', 'years' => 4, 'months' => 0])
            ->assertOk()->assertJsonPath('last_day', '2027-12-30')->assertJsonPath('file_by', '2027-12-31')
            ->assertJsonPath('adjustments.0.reason', 'Rizal Day');

        // 6 months from April 3, 2027 → Sunday, October 3, 2027 → Monday.
        $this->postJson('/api/v1/prescription-periods/preview', ['from' => '2027-04-03', 'years' => 0, 'months' => 6])
            ->assertJsonPath('last_day', '2027-10-03')->assertJsonPath('file_by', '2027-10-04');

        // A year from Feb 29 ends on Feb 28 (calendar months, no overflow into March).
        $this->postJson('/api/v1/prescription-periods/preview', ['from' => '2024-02-29', 'years' => 1, 'months' => 0])
            ->assertJsonPath('last_day', '2025-02-28');
    }

    public function test_a_written_demand_restarts_a_civil_period_but_not_a_criminal_one(): void
    {
        $civil = $this->postJson("/api/v1/matters/{$this->matter->id}/prescriptions", ['period_key' => 'oral_contract', 'accrued_on' => '2021-01-15'])->json('id');
        $this->postJson("/api/v1/prescriptions/{$civil}/interruptions", ['date' => '2025-06-01', 'kind' => 'demand', 'note' => 'Demand letter received by the debtor'])
            ->assertOk()
            ->assertJsonPath('runs_from', '2025-06-01')
            ->assertJsonPath('last_day', '2031-06-01')
            ->assertJsonPath('interruptions.0.kind_label', 'Written extrajudicial demand');

        $crime = $this->postJson("/api/v1/matters/{$this->matter->id}/prescriptions", ['period_key' => 'libel', 'accrued_on' => '2026-08-01'])->json('id');
        $this->postJson("/api/v1/prescriptions/{$crime}/interruptions", ['date' => '2026-09-01', 'kind' => 'demand'])->assertStatus(422)->assertJsonValidationErrors('kind');

        // Filing stops it; the firm-wide list no longer shows it.
        $this->postJson("/api/v1/prescriptions/{$crime}/filed", ['filed_on' => '2026-10-01'])->assertOk()->assertJsonPath('state', 'filed')->assertJsonPath('days_left', null);
        $this->assertSame([], collect($this->getJson('/api/v1/prescriptions?within=3650')->json('data'))->where('id', $crime)->all());
    }

    public function test_a_custom_period_and_the_firm_wide_list_soonest_first(): void
    {
        $this->postJson("/api/v1/matters/{$this->matter->id}/prescriptions", ['label' => 'Claim under the insurance policy', 'years' => 1, 'months' => 0, 'basis' => 'Policy, condition 12', 'accrued_on' => '2025-12-01'])
            ->assertCreated()->assertJsonPath('period_key', null)->assertJsonPath('last_day', '2026-12-01');
        $this->postJson("/api/v1/matters/{$this->matter->id}/prescriptions", ['period_key' => 'light_offense', 'accrued_on' => '2026-08-20'])->assertCreated();
        $this->postJson("/api/v1/matters/{$this->matter->id}/prescriptions", ['label' => 'Nothing', 'years' => 0, 'months' => 0, 'accrued_on' => '2026-01-01'])
            ->assertStatus(422)->assertJsonValidationErrors('years');
        $this->postJson("/api/v1/matters/{$this->matter->id}/prescriptions", ['period_key' => 'written_contract', 'accrued_on' => '2027-01-01'])
            ->assertStatus(422)->assertJsonValidationErrors('accrued_on');

        $rows = $this->getJson('/api/v1/prescriptions')->assertOk()->json('data');
        $this->assertSame(['Light offense', 'Claim under the insurance policy'], array_column($rows, 'label'));
        $this->assertSame('urgent', $rows[0]['state']);
        $this->assertSame($this->matter->reference, $rows[0]['matter']['reference']);
    }

    public function test_reminders_count_down_once_per_stage_and_escalate_in_the_last_month(): void
    {
        Notification::fake();
        // Last day: Nov 4, 2026 (30 days away).
        $this->postJson("/api/v1/matters/{$this->matter->id}/prescriptions", ['period_key' => 'oral_defamation', 'accrued_on' => '2026-05-04'])->assertCreated();

        $this->artisan('prescriptions:remind')->assertSuccessful();
        Notification::assertSentTo([$this->lawyer, $this->managingPartner], PrescriptionApproaching::class, fn ($n) => $n->daysLeft === 30);

        $this->artisan('prescriptions:remind')->assertSuccessful();
        Notification::assertSentTimes(PrescriptionApproaching::class, 2); // not again until the next stage

        Carbon::setTestNow('2026-10-21 09:00:00'); // 14 days
        $this->artisan('prescriptions:remind')->assertSuccessful();
        Notification::assertSentTimes(PrescriptionApproaching::class, 4);

        Carbon::setTestNow('2026-11-05 09:00:00'); // prescribed yesterday
        $this->artisan('prescriptions:remind')->assertSuccessful();
        Notification::assertSentTo($this->lawyer, PrescriptionApproaching::class, fn ($n) => $n->daysLeft === -1);
        $this->getJson('/api/v1/prescriptions')->assertJsonPath('data.0.state', 'prescribed');
    }

    public function test_a_period_far_off_is_not_reminded_yet_and_paralegals_can_record(): void
    {
        Notification::fake();
        $this->signIn(Role::Paralegal, $this->firm);
        $this->postJson("/api/v1/matters/{$this->matter->id}/prescriptions", ['period_key' => 'real_action', 'accrued_on' => '2020-01-01'])->assertCreated();
        $this->artisan('prescriptions:remind')->assertSuccessful();
        Notification::assertNothingSent();
    }

    public function test_another_firms_prescription_is_not_found(): void
    {
        $id = $this->postJson("/api/v1/matters/{$this->matter->id}/prescriptions", ['period_key' => 'quasi_delict', 'accrued_on' => '2025-01-01'])->json('id');
        $this->signIn(Role::Partner, Firm::factory()->create());
        $this->postJson("/api/v1/prescriptions/{$id}/filed")->assertNotFound();
        $this->getJson('/api/v1/prescriptions')->assertJsonCount(0, 'data');
    }
}
