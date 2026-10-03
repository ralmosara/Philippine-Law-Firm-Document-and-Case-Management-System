<?php

namespace Tests\Feature\Push;

use App\Domain\Billing\Models\TimeEntry;
use App\Domain\Matters\Models\Client;
use App\Domain\Matters\Models\Firm;
use App\Domain\Matters\Models\Matter;
use App\Domain\Push\Models\PushSubscription;
use App\Domain\Push\PushSender;
use App\Enums\Role;
use App\Models\User;
use App\Notifications\Concerns\ShowsInApp;
use Illuminate\Bus\Queueable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Notification;
use Tests\TestCase;

class PushNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private User $lawyer;

    /** @var list<array{endpoint: string, payload: array, urgent: bool}> */
    private array $sent = [];

    private ?int $answer = 201;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.webpush.public_key' => 'BPublicKeyForTests', 'services.webpush.private_key' => 'private']);
        $this->lawyer = $this->signIn(Role::Associate, Firm::factory()->create());

        $test = $this;
        $this->app->instance(PushSender::class, new class($test) extends PushSender
        {
            public function __construct(private readonly PushNotificationsTest $test) {}

            public function send(PushSubscription $subscription, array $payload, bool $urgent = false): ?int
            {
                return $this->test->record($subscription->endpoint, $payload, $urgent);
            }
        });
    }

    public function record(string $endpoint, array $payload, bool $urgent): ?int
    {
        $this->sent[] = compact('endpoint', 'payload', 'urgent');

        return $this->answer;
    }

    private function subscribe(string $endpoint = 'https://fcm.googleapis.com/fcm/send/abc123')
    {
        return $this->postJson('/api/v1/push/subscriptions', ['endpoint' => $endpoint, 'keys' => ['p256dh' => 'BKey', 'auth' => 'authsecret']]);
    }

    private function notifyLawyer(): void
    {
        $this->lawyer->notify(new MissedDeadlineForPush);
    }

    public function test_a_device_subscribes_and_gets_notifications_without_details_on_the_lock_screen(): void
    {
        $this->getJson('/api/v1/push')->assertOk()->assertJsonPath('enabled', true)->assertJsonPath('devices', 0)->assertJsonPath('push_details', false);
        $this->subscribe()->assertCreated()->assertJsonPath('devices', 1);
        $this->subscribe()->assertOk()->assertJsonPath('devices', 1); // the same device again

        $this->notifyLawyer();

        $this->assertCount(1, $this->sent);
        $this->assertSame('Something is overdue', $this->sent[0]['payload']['title']);
        $this->assertStringNotContainsString('Santos', json_encode($this->sent[0]['payload']));
        $this->assertSame('/matters/9', $this->sent[0]['payload']['url']);
        $this->assertTrue($this->sent[0]['urgent']);

        // Details only when the user chooses them.
        $this->putJson('/api/v1/push/preferences', ['push_details' => true])->assertOk()->assertJsonPath('push_details', true);
        $this->lawyer->refresh();
        $this->notifyLawyer();
        $this->assertSame('Missed: Answer in Santos v. Reyes', $this->sent[1]['payload']['title']);
    }

    public function test_only_real_push_services_are_accepted(): void
    {
        foreach (['http://fcm.googleapis.com/x', 'https://internal.lan/hook', 'https://169.254.169.254/latest', 'https://fcm.googleapis.com.evil.example/x', 'https://user:pw@fcm.googleapis.com/x', 'https://fcm.googleapis.com:8443/x'] as $bad) {
            $this->subscribe($bad)->assertStatus(422);
        }
        foreach (['https://updates.push.services.mozilla.com/wpush/v2/x', 'https://web.push.apple.com/abc', 'https://wns2-sg2p.notify.windows.com/w/?token=x'] as $good) {
            $this->subscribe($good)->assertSuccessful();
        }
        $this->assertSame(3, PushSubscription::count());
    }

    public function test_expired_devices_are_forgotten_and_turning_off_removes_one(): void
    {
        $this->subscribe('https://fcm.googleapis.com/fcm/send/old');
        $this->subscribe('https://fcm.googleapis.com/fcm/send/new');

        $this->answer = 410; // gone
        $this->notifyLawyer();
        $this->assertSame(0, PushSubscription::count());

        $this->answer = 201;
        $this->subscribe('https://fcm.googleapis.com/fcm/send/new');
        $this->postJson('/api/v1/push/subscriptions/remove', ['endpoint' => 'https://fcm.googleapis.com/fcm/send/new'])->assertOk()->assertJsonPath('devices', 0);

        // Nothing is pushed for users without devices, and nothing at all when push is not configured.
        $this->notifyLawyer();
        $this->subscribe();
        config(['services.webpush.private_key' => null]);
        $this->notifyLawyer();
        $this->assertCount(2, $this->sent);
    }

    public function test_a_test_notification_and_another_users_device_stays_theirs(): void
    {
        $this->postJson('/api/v1/push/test')->assertStatus(422);
        $this->subscribe();
        $this->postJson('/api/v1/push/test')->assertOk();
        $this->assertSame('test', $this->sent[0]['payload']['kind']);

        // Someone else cannot remove this user's device.
        $this->signIn(Role::Associate, Firm::factory()->create());
        $this->postJson('/api/v1/push/subscriptions/remove', ['endpoint' => 'https://fcm.googleapis.com/fcm/send/abc123'])->assertOk();
        $this->assertSame(1, PushSubscription::where('user_id', $this->lawyer->id)->count());
    }

    public function test_repeated_offline_writes_are_recorded_once(): void
    {
        $matter = Matter::factory()->for(Client::factory()->for(Firm::find($this->lawyer->firm_id)))->create(['firm_id' => $this->lawyer->firm_id]);
        $entry = ['matter_id' => $matter->id, 'work_date' => now()->toDateString(), 'minutes' => 90, 'description' => 'Hearing on the motion'];
        $key = ['Idempotency-Key' => 'a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d'];

        $first = $this->postJson('/api/v1/time-entries', $entry, $key)->assertCreated();
        $again = $this->postJson('/api/v1/time-entries', $entry, $key)->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

        $this->assertSame($first->json('data.id'), $again->json('data.id'));
        $this->assertSame(1, TimeEntry::count());

        // A different key is a different entry.
        $this->postJson('/api/v1/time-entries', $entry, ['Idempotency-Key' => 'ffffffff-e5f6-4a7b-8c9d-0e1f2a3b4c5d'])->assertCreated();
        $this->assertSame(2, TimeEntry::count());
    }
}

/** A staff notification as the app sends them: kind, title, body, link. */
class MissedDeadlineForPush extends Notification
{
    use Queueable, ShowsInApp;

    public function via(object $notifiable): array
    {
        return $this->withInApp($notifiable, []);
    }

    protected function inApp(object $notifiable): array
    {
        return ['kind' => 'deadline_missed', 'title' => 'Missed: Answer in Santos v. Reyes', 'body' => 'Juan dela Cruz', 'url' => '/matters/9'];
    }
}
