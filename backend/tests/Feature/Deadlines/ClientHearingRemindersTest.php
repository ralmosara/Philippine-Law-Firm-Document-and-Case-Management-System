<?php

namespace Tests\Feature\Deadlines;

use App\Domain\Deadlines\Notifications\ClientHearingNotice;
use App\Domain\Matters\Models\Client;
use App\Domain\Matters\Models\Firm;
use App\Domain\Matters\Models\Matter;
use App\Enums\Role;
use App\Notifications\Channels\SmsChannel;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ClientHearingRemindersTest extends TestCase
{
    use RefreshDatabase;

    private Firm $firm;

    private Client $client;

    private Matter $matter;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-05 08:00:00');
        Notification::fake();
        $this->firm = Firm::factory()->create(['name' => 'Santos Law', 'phone' => '(02) 8123 4567']);
        $this->signIn(Role::Partner, $this->firm);
        $this->client = Client::factory()->for($this->firm)->create(['name' => 'Juan Dela Cruz', 'email' => 'juan@example.com', 'phone' => '0917 555 0101']);
        $this->matter = Matter::factory()->for($this->client)->create(['title' => 'Dela Cruz v. Reyes', 'court' => 'Regional Trial Court', 'court_branch' => 'Branch 143, Makati City']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function hearing(array $extra = []): int
    {
        return $this->postJson("/api/v1/matters/{$this->matter->id}/deadlines", ['kind' => 'hearing', 'title' => 'Pre-trial', 'due_date' => '2026-10-20', 'due_time' => '13:30', ...$extra])->assertCreated()->json('data.id') ?? 0;
    }

    private function turnOn(): void
    {
        $this->firm->forceFill(['client_hearing_reminders' => true])->save();
    }

    public function test_nothing_goes_to_clients_until_the_firm_turns_it_on(): void
    {
        $this->hearing();
        $this->artisan('hearings:remind-clients')->assertSuccessful();
        Notification::assertNothingSentTo($this->client);
    }

    public function test_the_client_hears_when_a_hearing_is_set_moved_and_cancelled_by_email_and_sms(): void
    {
        $this->turnOn();
        $id = $this->postJson("/api/v1/matters/{$this->matter->id}/deadlines", ['kind' => 'hearing', 'title' => 'Pre-trial', 'due_date' => '2026-10-20', 'due_time' => '13:30'])->assertCreated()->json('id');

        Notification::assertSentTo($this->client, ClientHearingNotice::class, function (ClientHearingNotice $n, array $channels) {
            $mail = $n->toMail($this->client);
            $sms = $n->toSms($this->client)->content;

            return $n->kind === ClientHearingNotice::SCHEDULED
                && $channels === ['mail', SmsChannel::class]
                && $mail->subject === 'Hearing set: Dela Cruz v. Reyes'
                && in_array('When: Tuesday, October 20, 2026, 1:30 PM', $mail->introLines, true)
                && in_array('Where: Branch 143, Makati City, Regional Trial Court', $mail->introLines, true)
                && str_starts_with($sms, 'Santos Law: Hearing set. Dela Cruz v. Reyes. Tuesday, October 20, 2026, 1:30 PM')
                && str_ends_with($sms, 'Questions: (02) 8123 4567');
        });

        $this->postJson("/api/v1/deadlines/{$id}/reschedule", ['due_date' => '2026-11-03', 'reason' => 'Reset by the court'])->assertOk();
        Notification::assertSentTo($this->client, ClientHearingNotice::class, fn ($n) => $n->kind === ClientHearingNotice::MOVED && $n->from === '2026-10-20');

        $this->postJson("/api/v1/deadlines/{$id}/cancel", ['reason' => 'Judge on leave'])->assertOk();
        Notification::assertSentTo($this->client, ClientHearingNotice::class, fn ($n) => $n->kind === ClientHearingNotice::CANCELLED);
        Notification::assertSentTimes(ClientHearingNotice::class, 3);
    }

    public function test_reminders_go_a_week_before_and_the_day_before_once_each(): void
    {
        $this->turnOn();
        $this->hearing(['due_date' => '2026-10-12']);   // 7 days away
        Notification::fake();                            // forget the "set" notice

        $this->artisan('hearings:remind-clients')->assertSuccessful();
        $this->artisan('hearings:remind-clients')->assertSuccessful();
        Notification::assertSentTimes(ClientHearingNotice::class, 1);
        Notification::assertSentTo($this->client, ClientHearingNotice::class, fn ($n) => $n->kind === ClientHearingNotice::NEXT_WEEK);

        Carbon::setTestNow('2026-10-11 08:00:00');
        $this->artisan('hearings:remind-clients')->assertSuccessful();
        Notification::assertSentTo($this->client, ClientHearingNotice::class, fn ($n) => $n->kind === ClientHearingNotice::TOMORROW);
        $this->artisan('hearings:remind-clients')->assertSuccessful();
        Notification::assertSentTimes(ClientHearingNotice::class, 2);
    }

    public function test_a_client_or_a_hearing_can_be_left_out_and_email_only_clients_get_no_sms(): void
    {
        $this->turnOn();
        $this->hearing(['notify_client' => false]);
        Notification::assertNothingSentTo($this->client);

        $this->putJson("/api/v1/clients/{$this->client->id}", ['hearing_reminders' => false])->assertOk()->assertJsonPath('hearing_reminders', false);
        $this->hearing(['title' => 'Trial']);
        Notification::assertNothingSentTo($this->client);

        $this->client->refresh()->forceFill(['hearing_reminders' => true, 'phone' => null])->save();
        $this->hearing(['title' => 'Mediation']);
        Notification::assertSentTo($this->client, ClientHearingNotice::class, fn ($n, $channels) => $channels === ['mail']);
    }

    public function test_in_filipino_for_clients_who_use_the_portal_in_filipino(): void
    {
        $this->turnOn();
        $this->client->forceFill(['locale' => 'fil'])->save();
        $this->hearing();

        Notification::assertSentTo($this->client, ClientHearingNotice::class, function (ClientHearingNotice $n, $channels, $notifiable, $locale) {
            app()->setLocale('fil');
            $mail = $n->toMail($this->client);
            $sms = $n->toSms($this->client)->content;
            app()->setLocale('en');

            return $locale === 'fil'
                && $mail->subject === 'May itinakdang pagdinig: Dela Cruz v. Reyes'
                && in_array('Kailan: Martes, Oktubre 20, 2026, 1:30 PM', $mail->introLines, true)
                && str_contains($sms, 'May tanong: (02) 8123 4567');
        });
    }

    public function test_a_past_hearing_entered_late_is_not_announced(): void
    {
        $this->turnOn();
        $this->hearing(['due_date' => '2026-09-30']);
        Notification::assertNothingSentTo($this->client);
    }
}
