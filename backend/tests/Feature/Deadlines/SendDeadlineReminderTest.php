<?php

namespace Tests\Feature\Deadlines;

use App\Domain\Deadlines\Enums\DeadlineStatus;
use App\Domain\Deadlines\Enums\ReminderStage;
use App\Domain\Deadlines\Jobs\SendDeadlineReminder;
use App\Domain\Deadlines\Models\MatterDeadline;
use App\Domain\Deadlines\Notifications\DeadlineMissed;
use App\Domain\Deadlines\Notifications\DeadlineReminder;
use App\Domain\Deadlines\Services\ReminderDispatcher;
use App\Domain\Matters\Models\Client;
use App\Domain\Matters\Models\Firm;
use App\Domain\Matters\Models\Matter;
use App\Models\User;
use App\Notifications\Channels\SmsChannel;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SendDeadlineReminderTest extends TestCase
{
    use RefreshDatabase;

    private User $lawyer;

    private Matter $matter;

    protected function setUp(): void
    {
        parent::setUp();
        $firm = Firm::factory()->create();
        $this->lawyer = User::factory()->create(['firm_id' => $firm->id, 'mobile_number' => '09171234567']);
        $this->matter = Matter::factory()->for(Client::factory()->for($firm))->create(['responsible_lawyer_id' => $this->lawyer->id]);
    }

    public function test_reminders_advance_through_stages_exactly_once(): void
    {
        Queue::fake();
        $today = CarbonImmutable::parse('2026-07-06');
        $deadline = MatterDeadline::factory()->for($this->matter)->create(['due_date' => '2026-07-09', 'assigned_to' => $this->lawyer->id]);
        $dispatcher = app(ReminderDispatcher::class);

        $dispatcher->run($today);
        $dispatcher->run($today); // a second run the same day must not resend

        Queue::assertPushed(SendDeadlineReminder::class, 1);
        Queue::assertPushed(SendDeadlineReminder::class, fn ($job) => $job->stage === ReminderStage::ThreeDays);

        $dispatcher->run($today->addDays(2)); // one day out

        Queue::assertPushed(SendDeadlineReminder::class, 2);
        $this->assertSame(ReminderStage::OneDay, $deadline->fresh()->last_reminder_stage);
    }

    public function test_deadlines_outside_the_window_or_closed_get_no_reminder(): void
    {
        Queue::fake();
        MatterDeadline::factory()->for($this->matter)->create(['due_date' => '2026-08-30']);
        $done = MatterDeadline::factory()->for($this->matter)->create(['due_date' => '2026-07-07']);
        $done->forceFill(['status' => DeadlineStatus::Completed])->save();

        app(ReminderDispatcher::class)->run(CarbonImmutable::parse('2026-07-06'));

        Queue::assertNothingPushed();
    }

    public function test_the_job_notifies_by_mail_and_sms_and_logs_the_event(): void
    {
        Notification::fake();
        $deadline = MatterDeadline::factory()->for($this->matter)->create(['assigned_to' => $this->lawyer->id]);

        (new SendDeadlineReminder($deadline->id, ReminderStage::OneDay))->handle();

        Notification::assertSentTo($this->lawyer, DeadlineReminder::class, function ($notification, array $channels) {
            return $channels === ['mail', SmsChannel::class, 'database', 'broadcast'];
        });
        Notification::assertCount(1); // assignee and responsible lawyer are the same person

        $event = $deadline->events()->first();
        $this->assertSame('reminder_sent', $event->event_type);
        $this->assertSame('24h', $event->payload['stage']);
    }

    public function test_early_reminders_are_email_only(): void
    {
        Notification::fake();
        $deadline = MatterDeadline::factory()->for($this->matter)->create(['assigned_to' => $this->lawyer->id]);

        (new SendDeadlineReminder($deadline->id, ReminderStage::SevenDays))->handle();

        Notification::assertSentTo($this->lawyer, DeadlineReminder::class, fn ($n, array $channels) => $channels === ['mail', 'database', 'broadcast']); // e-mail and the bell, no SMS
    }

    public function test_a_completed_deadline_is_not_reminded(): void
    {
        Notification::fake();
        $deadline = MatterDeadline::factory()->for($this->matter)->create();
        $deadline->forceFill(['status' => DeadlineStatus::Completed])->save();

        (new SendDeadlineReminder($deadline->id, ReminderStage::DayOf))->handle();

        Notification::assertNothingSent();
        $this->assertSame(0, $deadline->events()->count());
    }

    public function test_overdue_deadlines_are_marked_missed_and_escalated(): void
    {
        Notification::fake();
        Queue::fake();
        $deadline = MatterDeadline::factory()->for($this->matter)->create(['due_date' => '2026-07-01']);

        $result = app(ReminderDispatcher::class)->run(CarbonImmutable::parse('2026-07-06'));

        $this->assertSame(1, $result['missed']);
        $this->assertSame(DeadlineStatus::Missed, $deadline->fresh()->status);
        $this->assertSame('missed', $deadline->events()->first()->event_type);
        Notification::assertSentTo($this->lawyer, DeadlineMissed::class);
    }
}
