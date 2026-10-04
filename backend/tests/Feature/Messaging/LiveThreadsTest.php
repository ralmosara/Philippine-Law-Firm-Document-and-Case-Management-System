<?php

namespace Tests\Feature\Messaging;

use App\Domain\Assistant\Events\AssistantAnswered;
use App\Domain\Matters\Models\Client;
use App\Domain\Matters\Models\Firm;
use App\Domain\Matters\Models\Matter;
use App\Domain\Messaging\Events\MessageThreadUpdated;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class LiveThreadsTest extends TestCase
{
    use RefreshDatabase;

    private Firm $firm;

    private User $lawyer;

    private Client $client;

    private Matter $matter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->firm = Firm::factory()->create();
        $this->lawyer = $this->signIn(Role::Partner, $this->firm);
        $this->client = Client::factory()->for($this->firm)->withPortal('secret-pass')->create();
        $this->matter = Matter::factory()->for($this->client)->create(['responsible_lawyer_id' => $this->lawyer->id]);
    }

    public function test_a_new_message_is_announced_on_the_thread_both_inboxes_without_its_text(): void
    {
        Event::fake([MessageThreadUpdated::class]);
        $this->actingAs($this->client, 'client');
        $id = $this->postJson('/api/portal/message-threads', ['matter_id' => $this->matter->id, 'subject' => 'Hearing', 'body' => 'Secret details of my case'])->assertCreated()->json('id');

        Event::assertDispatched(MessageThreadUpdated::class, function (MessageThreadUpdated $e) use ($id) {
            $channels = array_map(fn ($c) => $c->name, $e->broadcastOn());

            return $e->change === 'message'
                && $channels === ["private-message-thread.{$id}", "private-firm.{$this->firm->id}.messages", "private-portal-client.{$this->client->id}"]
                && ! str_contains(json_encode($e->broadcastWith()), 'Secret');
        });
    }

    public function test_reading_is_announced_only_when_something_new_was_read(): void
    {
        $this->actingAs($this->client, 'client');
        $id = $this->postJson('/api/portal/message-threads', ['matter_id' => $this->matter->id, 'subject' => 'Hearing', 'body' => 'Question'])->json('id');

        Event::fake([MessageThreadUpdated::class]);
        $this->actingAs($this->lawyer, 'web')->actingAs($this->lawyer, 'sanctum');
        $this->getJson("/api/v1/message-threads/{$id}")->assertOk();
        $this->getJson("/api/v1/message-threads/{$id}")->assertOk(); // nothing new: no announcement, no ping-pong
        Event::assertDispatchedTimes(MessageThreadUpdated::class, 1);
        Event::assertDispatched(MessageThreadUpdated::class, fn ($e) => $e->change === 'read');
    }

    public function test_only_the_firms_staff_and_the_threads_client_may_listen(): void
    {
        $this->actingAs($this->client, 'client');
        $id = $this->postJson('/api/portal/message-threads', ['matter_id' => $this->matter->id, 'subject' => 'Hearing', 'body' => 'Question'])->json('id');
        $channels = app(BroadcastManager::class)->driver()->getChannels();
        $thread = $channels['message-thread.{id}'];
        $firmInbox = $channels['firm.{firmId}.messages'];
        $portal = $channels['portal-client.{id}'];

        $otherFirm = Firm::factory()->create();
        $outsider = User::factory()->create(['firm_id' => $otherFirm->id]);
        $otherClient = Client::factory()->for($this->firm)->withPortal('x-secret-pass')->create();

        $this->assertTrue($thread($this->lawyer, $id));
        $this->assertTrue($thread($this->client, $id));
        $this->assertFalse($thread($outsider, $id));
        $this->assertFalse($thread($otherClient, $id));
        $this->assertFalse($thread($this->lawyer, 999999));

        $this->assertTrue($firmInbox($this->lawyer, $this->firm->id));
        $this->assertFalse($firmInbox($outsider, $this->firm->id));
        $this->assertTrue($portal($this->client, $this->client->id));
        $this->assertFalse($portal($otherClient, $this->client->id));

        $this->client->forceFill(['portal_enabled' => false])->save();
        $this->assertFalse($thread($this->client->fresh(), $id), 'Portal access withdrawn: no more live updates.');
    }

    public function test_the_portal_learns_how_to_connect(): void
    {
        config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.key' => 'app-key']);
        $this->actingAs($this->client, 'client');
        $this->getJson('/api/portal/me')->assertJsonPath('client.realtime.key', 'app-key');

        config(['broadcasting.default' => 'null']);
        $this->getJson('/api/portal/me')->assertJsonPath('client.realtime', null);
    }

    public function test_the_assistant_answer_goes_to_the_asking_lawyer_only(): void
    {
        $event = new AssistantAnswered($this->lawyer->id, 12, $this->matter->id);
        $this->assertSame(["private-App.Models.User.{$this->lawyer->id}"], array_map(fn ($c) => $c->name, $event->broadcastOn()));
        $this->assertSame(['conversation_id' => 12, 'matter_id' => $this->matter->id], $event->broadcastWith());
    }
}
