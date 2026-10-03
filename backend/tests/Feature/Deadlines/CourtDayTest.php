<?php

namespace Tests\Feature\Deadlines;

use App\Domain\Billing\Models\TimeEntry;
use App\Domain\Deadlines\Models\MatterDeadline;
use App\Domain\Matters\Models\Client;
use App\Domain\Matters\Models\Firm;
use App\Domain\Matters\Models\Matter;
use App\Enums\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CourtDayTest extends TestCase
{
    use RefreshDatabase;

    private Firm $firm;

    private User $lawyer;

    private Matter $matter;

    protected function setUp(): void
    {
        parent::setUp();
        // A Monday, so a 30-day period lands on a known day.
        $this->travelTo(CarbonImmutable::parse('2026-10-05 07:30', 'Asia/Manila'));
        $this->firm = Firm::factory()->create();
        $this->lawyer = $this->signIn(Role::Partner, $this->firm, ['hourly_rate_cents' => 600_000]);
        $this->matter = Matter::factory()->for(Client::factory()->for($this->firm)->create(['name' => 'Juan Dela Cruz']))->create([
            'title' => 'Dela Cruz v. Reyes', 'case_number' => 'CV-2026-100', 'court' => 'Regional Trial Court', 'court_branch' => 'Branch 58, Makati City', 'judge' => 'Hon. Ana Cruz',
            'responsible_lawyer_id' => $this->lawyer->id,
        ]);
        $this->matter->parties()->create(['role' => 'adverse_party', 'name' => 'Pedro Reyes', 'counsel_name' => 'Atty. Jose Rizal']);
    }

    private function hearing(string $time = '08:30', ?int $assignee = null): MatterDeadline
    {
        return MatterDeadline::create([
            'firm_id' => $this->firm->id, 'matter_id' => $this->matter->id, 'kind' => 'hearing', 'title' => 'Pre-trial conference',
            'due_date' => today()->toDateString(), 'due_time' => $time, 'location' => 'Room 305', 'assigned_to' => $assignee ?? $this->lawyer->id,
            'notes' => 'Bring the original contract',
        ]);
    }

    public function test_the_agenda_lists_todays_hearings_in_time_order_with_what_to_bring(): void
    {
        $this->hearing('13:30');
        $this->hearing('08:30');
        $other = User::factory()->role(Role::Associate)->create(['firm_id' => $this->firm->id]);
        $this->hearing('10:00', $other->id);
        MatterDeadline::create(['firm_id' => $this->firm->id, 'matter_id' => $this->matter->id, 'kind' => 'filing', 'title' => 'File brief', 'due_date' => today()->toDateString()]);

        $this->getJson('/api/v1/court-day')
            ->assertOk()
            ->assertJsonPath('date', '2026-10-05')
            ->assertJsonCount(3, 'hearings')
            ->assertJsonPath('hearings.0.time', '08:30')
            ->assertJsonPath('hearings.2.time', '13:30')
            ->assertJsonPath('hearings.0.notes', 'Bring the original contract')
            ->assertJsonPath('hearings.0.matter.court', 'Regional Trial Court, Branch 58, Makati City')
            ->assertJsonPath('hearings.0.matter.judge', 'Hon. Ana Cruz')
            ->assertJsonPath('hearings.0.matter.opposing.0', 'Pedro Reyes')
            ->assertJsonPath('hearings.0.matter.opposing_counsel.0', 'Atty. Jose Rizal');

        $this->getJson('/api/v1/court-day?mine=1')->assertJsonCount(2, 'hearings');
        $this->getJson('/api/v1/court-day?date=2026-10-06')->assertJsonCount(0, 'hearings');
    }

    public function test_a_held_hearing_records_the_next_setting_orders_and_time(): void
    {
        $hearing = $this->hearing();

        $this->postJson("/api/v1/deadlines/{$hearing->id}/hearing-outcome", [
            'outcome' => 'held',
            'notes' => 'Pre-trial terminated; trial set.',
            'next_date' => '2026-11-12', 'next_time' => '09:00',
            'follow_ups' => [['title' => 'File judicial affidavits', 'days' => 30]],
            'minutes' => 90,
        ])->assertOk()
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('next.date', '2026-11-12')
            // 30 days from Oct 5 is Nov 4 (a Wednesday, a working day).
            ->assertJsonPath('follow_ups.0.due_date', '2026-11-04');

        $this->assertSame('completed', $hearing->fresh()->status->value);
        $next = MatterDeadline::where('title', 'Continuation of hearing')->firstOrFail();
        $this->assertSame('hearing', $next->kind->value);
        $this->assertSame('Room 305', $next->location);
        $this->assertSame('Ordered at the hearing of October 5, 2026.', MatterDeadline::where('title', 'File judicial affidavits')->value('notes'));

        $entry = TimeEntry::firstOrFail();
        $this->assertSame(90, $entry->minutes);
        $this->assertSame(900_000, $entry->amount_cents); // 1.5 h at the lawyer's rate
        $this->assertStringStartsWith('Appearance: Pre-trial conference', $entry->description);

        $this->postJson("/api/v1/deadlines/{$hearing->id}/hearing-outcome", ['outcome' => 'held'])->assertStatus(422);
    }

    public function test_an_outcome_sent_the_next_day_counts_from_the_hearing_once(): void
    {
        $hearing = $this->hearing();
        // Recorded in court with no signal; the phone sends it the next morning, twice.
        $this->travelTo(CarbonImmutable::parse('2026-10-06 08:15', 'Asia/Manila'));
        $outcome = ['outcome' => 'held', 'follow_ups' => [['title' => 'File judicial affidavits', 'days' => 30]], 'minutes' => 60];
        $key = ['Idempotency-Key' => '5f0c1e2a-9b8d-4c7e-a6f5-3d2c1b0a9e8f'];

        $this->postJson("/api/v1/deadlines/{$hearing->id}/hearing-outcome", $outcome, $key)->assertOk()
            ->assertJsonPath('follow_ups.0.due_date', '2026-11-04'); // from Oct 5, the hearing, not Oct 6
        $this->postJson("/api/v1/deadlines/{$hearing->id}/hearing-outcome", $outcome, $key)->assertOk()->assertHeader('Idempotent-Replayed', 'true');

        $this->assertSame(1, MatterDeadline::where('title', 'File judicial affidavits')->count());
        $this->assertSame('2026-10-05', TimeEntry::sole()->work_date->toDateString());
    }

    public function test_a_reset_hearing_moves_to_its_new_date_and_keeps_its_history(): void
    {
        $hearing = $this->hearing();

        $this->postJson("/api/v1/deadlines/{$hearing->id}/hearing-outcome", ['outcome' => 'reset'])->assertStatus(422)->assertJsonValidationErrors('next_date');
        $this->postJson("/api/v1/deadlines/{$hearing->id}/hearing-outcome", ['outcome' => 'reset', 'next_date' => '2026-10-20', 'next_time' => '14:00', 'notes' => 'Judge on leave'])
            ->assertOk()->assertJsonPath('status', 'pending')->assertJsonPath('next.id', $hearing->id);

        $fresh = $hearing->fresh();
        $this->assertSame('2026-10-20', $fresh->due_date->toDateString());
        $this->assertSame('14:00', substr((string) $fresh->due_time, 0, 5));
        $this->assertDatabaseHas('deadline_events', ['matter_deadline_id' => $hearing->id, 'event_type' => 'rescheduled']);
        $this->assertSame(1, MatterDeadline::count()); // no duplicate hearing
    }

    public function test_only_hearings_have_outcomes_and_other_firms_cannot_touch_them(): void
    {
        $filing = MatterDeadline::create(['firm_id' => $this->firm->id, 'matter_id' => $this->matter->id, 'kind' => 'filing', 'title' => 'File brief', 'due_date' => today()->toDateString()]);
        $this->postJson("/api/v1/deadlines/{$filing->id}/hearing-outcome", ['outcome' => 'held'])->assertStatus(422);

        $hearing = $this->hearing();
        $this->signIn(Role::Partner, Firm::factory()->create());
        $this->postJson("/api/v1/deadlines/{$hearing->id}/hearing-outcome", ['outcome' => 'cancelled'])->assertNotFound();
        $this->getJson('/api/v1/court-day')->assertJsonCount(0, 'hearings');
    }
}
