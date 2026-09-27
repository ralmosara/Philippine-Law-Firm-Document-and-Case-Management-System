<?php

namespace Tests\Feature\Notifications;

use App\Domain\Matters\Models\Client;
use App\Domain\Matters\Models\Firm;
use App\Domain\Matters\Models\Matter;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationCenterTest extends TestCase
{
    use RefreshDatabase;

    private Firm $firm;

    private User $partner;

    private User $associate;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->firm = Firm::factory()->create();
        $this->associate = User::factory()->role(Role::Associate)->create(['firm_id' => $this->firm->id, 'name' => 'Atty. Jose Rizal']);
        $this->partner = $this->signIn(Role::ManagingPartner, $this->firm, ['name' => 'Atty. Maria Santos']);
        $this->client = Client::factory()->for($this->firm)->withPortal('secret-pass')->create(['name' => 'Juan Dela Cruz']);
    }

    public function test_assigning_a_matter_or_deadline_to_someone_else_notifies_them(): void
    {
        $matterId = $this->postJson('/api/v1/matters', ['client_id' => $this->client->id, 'title' => 'Dela Cruz v. Reyes', 'case_type' => 'Civil', 'responsible_lawyer_id' => $this->associate->id])
            ->assertCreated()->json('id');

        $this->postJson("/api/v1/matters/{$matterId}/deadlines", ['kind' => 'filing', 'title' => 'File answer', 'due_date' => today()->addDays(10)->toDateString(), 'assigned_to' => $this->associate->id])->assertCreated();
        $mine = $this->postJson("/api/v1/matters/{$matterId}/deadlines", ['kind' => 'task', 'title' => 'Call client', 'due_date' => today()->addDays(2)->toDateString(), 'assigned_to' => $this->partner->id])->assertCreated()->json('id');

        $this->assertSame(0, $this->partner->notifications()->count()); // assigning yourself is not news
        $this->assertSame(2, $this->associate->notifications()->count());

        // Reassigning later notifies the new person.
        $this->patchJson("/api/v1/deadlines/{$mine}", ['assigned_to' => $this->associate->id])->assertOk();
        $this->assertSame(3, $this->associate->notifications()->count());

        $this->actingAs($this->associate, 'web')->actingAs($this->associate, 'sanctum');
        $response = $this->getJson('/api/v1/notifications')->assertOk()->assertJsonPath('unread', 3)->assertJsonPath('realtime', null);
        $items = collect($response->json('data'));
        $this->assertSame(['assigned'], $items->pluck('kind')->unique()->values()->all());
        $this->assertContains('Atty. Maria Santos assigned you: Call client', $items->pluck('title'));
        $this->assertContains('Atty. Maria Santos assigned you: File answer', $items->pluck('title'));
        $matterNotice = $items->first(fn ($i) => str_starts_with($i['title'], 'Atty. Maria Santos made you responsible for M-'));
        $this->assertSame("/matters/{$matterId}", $matterNotice['url']);
    }

    public function test_notifications_are_marked_read_one_by_one_or_all_and_stay_private(): void
    {
        $matter = Matter::factory()->for($this->client)->create();
        $this->patchJson("/api/v1/matters/{$matter->id}", ['responsible_lawyer_id' => $this->associate->id])->assertOk();
        $this->postJson("/api/v1/matters/{$matter->id}/deadlines", ['kind' => 'filing', 'title' => 'Brief', 'due_date' => today()->addDays(5)->toDateString(), 'assigned_to' => $this->associate->id])->assertCreated();
        [$first, $second] = $this->associate->notifications()->pluck('id')->all();

        // Another user cannot read or mark someone else's.
        $this->postJson("/api/v1/notifications/{$first}/read")->assertNotFound();

        $this->actingAs($this->associate, 'web')->actingAs($this->associate, 'sanctum');
        $this->postJson("/api/v1/notifications/{$first}/read")->assertOk()->assertJsonPath('unread', 1);
        $this->postJson('/api/v1/notifications/read-all')->assertOk()->assertJsonPath('unread', 0);
        $this->assertNotNull($this->associate->notifications()->find($second)->read_at);
    }

    public function test_a_client_message_reaches_the_bell_and_privacy_requests_reach_managing_partners(): void
    {
        $matter = Matter::factory()->for($this->client)->create(['responsible_lawyer_id' => $this->associate->id]);

        $this->actingAs($this->client, 'client');
        $this->postJson('/api/portal/message-threads', ['matter_id' => $matter->id, 'subject' => 'Hearing', 'body' => 'Will I need to testify?'])->assertCreated();
        $this->postJson('/api/portal/privacy/requests', ['type' => 'access'])->assertCreated();

        $this->assertSame('message', $this->associate->notifications()->first()->data['kind']);
        $this->assertSame('New message from Juan Dela Cruz', $this->associate->notifications()->first()->data['title']);
        $this->assertSame('privacy', $this->partner->notifications()->where('type', 'privacy')->first()->data['kind']);
        $this->assertSame(0, $this->associate->notifications()->where('type', 'privacy')->count()); // partners only
    }

    public function test_only_the_owner_may_listen_on_their_private_channel(): void
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'app-key',
            'broadcasting.connections.reverb.secret' => 'app-secret',
            'broadcasting.connections.reverb.app_id' => '1001',
        ]);
        // Channels register on the broadcaster active at boot (null in tests); register them on Reverb.
        app(BroadcastManager::class)->purge();
        require base_path('routes/channels.php');

        $this->postJson('/api/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => "private-App.Models.User.{$this->partner->id}"])
            ->assertOk()->assertJsonStructure(['auth']);
        $this->postJson('/api/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => "private-App.Models.User.{$this->associate->id}"])
            ->assertForbidden();

        $this->getJson('/api/v1/notifications')->assertJsonPath('realtime.key', 'app-key');
    }
}
