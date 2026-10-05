<?php

namespace Tests\Feature\Billing;

use App\Domain\Billing\Models\TimeEntry;
use App\Domain\Billing\TimeReminders\Notifications\MissingTimeReminder;
use App\Domain\Billing\TimeReminders\Notifications\WeeklyTimeSummary;
use App\Domain\Billing\TimeReminders\TimeReminders;
use App\Domain\Deadlines\Models\HolidayCalendar;
use App\Domain\Matters\Models\Firm;
use App\Domain\Matters\Models\Matter;
use App\Enums\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class TimeRemindersTest extends TestCase
{
    use RefreshDatabase;

    private Firm $firm;

    private Matter $matter;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->firm = Firm::factory()->create(['time_reminders_enabled' => true, 'daily_target_minutes' => 360]);
        $this->matter = Matter::factory()->create(['firm_id' => $this->firm->id]);
    }

    private function person(Role $role, array $attributes = []): User
    {
        return User::factory()->role($role)->create(['firm_id' => $this->firm->id, ...$attributes]);
    }

    private function log(User $user, string $date, int $minutes): void
    {
        TimeEntry::factory()->for($this->matter)->create(['user_id' => $user->id, 'work_date' => $date, 'minutes' => $minutes]);
    }

    public function test_people_short_of_their_target_on_the_previous_working_day_are_reminded_once(): void
    {
        $short = $this->person(Role::Associate);
        $none = $this->person(Role::Paralegal);
        $met = $this->person(Role::Partner);
        $staff = $this->person(Role::Staff);
        $optedOut = $this->person(Role::Associate, ['daily_target_minutes' => 0]);
        $inactive = $this->person(Role::Associate, ['is_active' => false]);
        $this->log($short, '2026-10-02', 120);
        $this->log($met, '2026-10-02', 240);
        $this->log($met, '2026-10-02', 150);

        // Monday 5 October: the previous working day is Friday the 2nd.
        $reminders = app(TimeReminders::class);
        $this->assertSame(2, $reminders->remindMissing(CarbonImmutable::parse('2026-10-05')));
        $this->assertSame(0, $reminders->remindMissing(CarbonImmutable::parse('2026-10-05')), 'Once per day.');

        Notification::assertSentTo($short, MissingTimeReminder::class, fn ($n) => $n->day->toDateString() === '2026-10-02' && $n->minutes === 120 && $n->target === 360);
        Notification::assertSentTo($none, MissingTimeReminder::class);
        Notification::assertNotSentTo([$met, $staff, $optedOut, $inactive], MissingTimeReminder::class);
    }

    public function test_weekends_and_holidays_are_skipped(): void
    {
        $user = $this->person(Role::Associate);
        HolidayCalendar::create(['date' => '2026-10-02', 'name' => 'Test Holiday', 'type' => 'special_non_working']);
        $reminders = app(TimeReminders::class);

        $this->assertSame(0, $reminders->remindMissing(CarbonImmutable::parse('2026-10-04')), 'No reminders on a Sunday.');
        $this->assertSame('2026-10-01', $reminders->previousWorkingDay(CarbonImmutable::parse('2026-10-05'))->toDateString());

        $reminders->remindMissing(CarbonImmutable::parse('2026-10-05'));
        Notification::assertSentTo($user, MissingTimeReminder::class, fn ($n) => $n->day->toDateString() === '2026-10-01');
    }

    public function test_a_personal_target_overrides_the_firms(): void
    {
        $partTime = $this->person(Role::Associate, ['daily_target_minutes' => 120]);
        $this->log($partTime, '2026-10-02', 120);

        $this->assertSame(0, app(TimeReminders::class)->remindMissing(CarbonImmutable::parse('2026-10-05')));
    }

    public function test_nothing_is_sent_until_the_firm_turns_it_on(): void
    {
        $this->firm->update(['time_reminders_enabled' => false]);
        $this->person(Role::Associate);

        $this->assertSame(0, app(TimeReminders::class)->remindMissing(CarbonImmutable::parse('2026-10-05')));
        $this->assertSame(0, app(TimeReminders::class)->sendWeeklySummary(CarbonImmutable::parse('2026-10-05')));
    }

    public function test_the_managing_partner_gets_last_weeks_hours_against_target(): void
    {
        $mp = $this->person(Role::ManagingPartner);
        $associate = $this->person(Role::Associate, ['name' => 'Ana Reyes']);
        HolidayCalendar::create(['date' => '2026-10-02', 'name' => 'Test Holiday', 'type' => 'special_non_working']);
        $this->log($associate, '2026-09-29', 600);
        $this->log($associate, '2026-10-03', 60);   // Saturday still counts as time logged
        $this->log($associate, '2026-10-06', 600);  // this week: not counted

        $this->assertSame(1, app(TimeReminders::class)->sendWeeklySummary(CarbonImmutable::parse('2026-10-05')));

        Notification::assertSentTo($mp, WeeklyTimeSummary::class, function (WeeklyTimeSummary $n) {
            $ana = collect($n->rows)->firstWhere('name', 'Ana Reyes');

            // Monday 28 September to Sunday 4 October: four working days, the 2nd a holiday.
            return $n->from->toDateString() === '2026-09-28' && $ana['minutes'] === 660 && $ana['target'] === 4 * 360;
        });
        Notification::assertNotSentTo($associate, WeeklyTimeSummary::class);
    }

    public function test_the_firm_target_and_personal_targets_are_editable(): void
    {
        $mp = $this->signIn(Role::ManagingPartner, $this->firm);
        $this->putJson('/api/v1/firm', ['daily_target_minutes' => 420, 'time_reminders_enabled' => true])
            ->assertOk()->assertJsonPath('daily_target_minutes', 420);
        $this->putJson('/api/v1/firm', ['daily_target_minutes' => 5])->assertJsonValidationErrors('daily_target_minutes');

        $this->putJson("/api/v1/users/{$mp->id}", ['name' => $mp->name, 'email' => $mp->email, 'role' => 'managing_partner', 'daily_target_minutes' => 0])
            ->assertOk()->assertJsonPath('daily_target_minutes', 0);
    }
}
