<?php

namespace Tests\Feature\Deadlines;

use App\Domain\Billing\Models\TimeEntry;
use App\Domain\Deadlines\Enums\ReminderStage;
use App\Domain\Deadlines\Jobs\SendDeadlineReminder;
use App\Domain\Deadlines\Models\MatterDeadline;
use App\Domain\Deadlines\Notifications\DeadlineReminder;
use App\Domain\Documents\Models\Document;
use App\Domain\Matters\Models\Client;
use App\Domain\Matters\Models\Firm;
use App\Domain\Matters\Models\Matter;
use App\Domain\Trust\Models\TrustAccount;
use App\Domain\Trust\Services\TrustLedgerService;
use App\Enums\Role;
use App\Models\User;
use App\Notifications\WorkAssigned;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ClosingClashesAndCoverTest extends TestCase
{
    use RefreshDatabase;

    private Firm $firm;

    private User $lawyer;

    private Matter $matter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-06 09:00', 'Asia/Manila'));
        $this->firm = Firm::factory()->create();
        $this->lawyer = $this->signIn(Role::Partner, $this->firm);
        $this->matter = Matter::factory()->for(Client::factory()->for($this->firm))->create(['responsible_lawyer_id' => $this->lawyer->id, 'status' => 'trial']);
    }

    private function close(array $extra = [])
    {
        return $this->postJson("/api/v1/matters/{$this->matter->id}/status", ['status' => 'closed', 'reason' => 'Judgment satisfied', 'ask_feedback' => false, ...$extra]);
    }

    public function test_funds_in_trust_block_closing_until_returned(): void
    {
        $account = TrustAccount::factory()->for($this->firm)->create(['client_id' => $this->matter->client_id, 'matter_id' => $this->matter->id]);
        app(TrustLedgerService::class)->deposit($account, 1_000_000, 'Deposit', by: $this->lawyer);

        $this->getJson("/api/v1/matters/{$this->matter->id}/closing-check")->assertOk()->assertJsonPath('blockers.0.key', 'trust');
        $this->close(['acknowledge_warnings' => true])->assertJsonValidationErrors('closing');

        app(TrustLedgerService::class)->disburse($account, 1_000_000, 'Returned to client', by: $this->lawyer);
        $this->close()->assertOk()->assertJsonPath('status', 'closed');
    }

    public function test_open_work_must_be_acknowledged_and_open_deadlines_are_cancelled(): void
    {
        TimeEntry::factory()->for($this->matter)->create(['user_id' => $this->lawyer->id, 'minutes' => 60, 'rate_cents' => 500_000]);
        $hearing = MatterDeadline::factory()->create(['matter_id' => $this->matter->id, 'firm_id' => $this->firm->id, 'kind' => 'hearing', 'due_date' => '2026-11-03']);

        $keys = array_column($this->getJson("/api/v1/matters/{$this->matter->id}/closing-check")->json('warnings'), 'key');
        $this->assertSame(['unbilled_time', 'open_deadlines'], $keys);

        $this->close()->assertJsonValidationErrors('closing');
        $this->close(['acknowledge_warnings' => true])->assertOk();
        $this->assertSame('cancelled', $hearing->fresh()->status->value);

        $id = $this->postJson("/api/v1/matters/{$this->matter->id}/closing-letter")->assertCreated()->json('id');
        $text = Document::find($id)->versions()->latest('version_number')->first()->content;
        $this->assertStringContainsString('our work on the above matter has concluded', $text);
        $this->assertStringContainsString('Judgment satisfied', $text);
    }

    public function test_a_lawyers_second_hearing_at_the_same_time_is_flagged(): void
    {
        $other = Matter::factory()->for(Client::factory()->for($this->firm))->create(['responsible_lawyer_id' => $this->lawyer->id]);
        MatterDeadline::factory()->create(['matter_id' => $other->id, 'firm_id' => $this->firm->id, 'kind' => 'hearing', 'title' => 'Pre-trial', 'due_date' => '2026-11-03', 'due_time' => '08:30', 'assigned_to' => $this->lawyer->id]);

        $clash = fn (string $time) => $this->getJson("/api/v1/deadlines/clashes?date=2026-11-03&time={$time}&matter_id={$this->matter->id}")->assertOk()->json('clashes');
        $this->assertSame('Pre-trial', $clash('09:30')[0]['title']);
        $this->assertSame([], $clash('13:30'), 'The afternoon is free.');

        $this->postJson("/api/v1/matters/{$this->matter->id}/deadlines", ['kind' => 'hearing', 'title' => 'Hearing on the motion', 'due_date' => '2026-11-03', 'due_time' => '09:00'])->assertCreated();
        $day = $this->getJson('/api/v1/court-day?date=2026-11-03')->assertOk()->json('hearings');
        $this->assertCount(1, $day[0]['clashes']);
        $this->assertCount(1, $day[1]['clashes']);
    }

    public function test_reminders_and_assignments_reach_the_cover_while_someone_is_away(): void
    {
        Notification::fake();
        $cover = User::factory()->role(Role::Associate)->create(['firm_id' => $this->firm->id]);
        $this->putJson('/api/v1/auth/away', ['away_from' => '2026-10-05', 'away_until' => '2026-10-16', 'cover_user_id' => $this->lawyer->id])->assertJsonValidationErrors('cover_user_id');
        $this->putJson('/api/v1/auth/away', ['away_from' => '2026-10-05', 'away_until' => '2026-10-16', 'cover_user_id' => $cover->id])->assertOk()->assertJsonPath('user.is_away', true);

        $deadline = MatterDeadline::factory()->create(['matter_id' => $this->matter->id, 'firm_id' => $this->firm->id, 'kind' => 'filing', 'due_date' => '2026-10-09', 'assigned_to' => $this->lawyer->id]);
        (new SendDeadlineReminder($deadline->id, ReminderStage::ThreeDays))->handle();
        Notification::assertSentTo([$this->lawyer, $cover], DeadlineReminder::class);

        $this->signIn(Role::ManagingPartner, $this->firm);
        $this->postJson("/api/v1/matters/{$this->matter->id}/deadlines", ['kind' => 'task', 'title' => 'Draft reply', 'due_date' => '2026-10-12', 'assigned_to' => $this->lawyer->id])->assertCreated();
        Notification::assertSentTo([$this->lawyer, $cover], WorkAssigned::class);

        // Back from leave: no more copies.
        $this->travelTo(CarbonImmutable::parse('2026-10-19 09:00', 'Asia/Manila'));
        Notification::fake();
        (new SendDeadlineReminder($deadline->id, ReminderStage::OneDay))->handle();
        Notification::assertNotSentTo($cover, DeadlineReminder::class);
    }
}
