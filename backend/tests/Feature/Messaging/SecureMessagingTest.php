<?php

namespace Tests\Feature\Messaging;

use App\Domain\Matters\Models\Client;
use App\Domain\Matters\Models\Firm;
use App\Domain\Matters\Models\Matter;
use App\Domain\Messaging\Models\Message;
use App\Domain\Messaging\Notifications\NewMessage;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Tests\TestCase;

class SecureMessagingTest extends TestCase
{
    use RefreshDatabase;

    private Firm $firm;

    private Client $client;

    private Matter $matter;

    private User $lawyer;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Storage::fake('local');
        $this->firm = Firm::factory()->create();
        $this->client = Client::factory()->for($this->firm)->withPortal('secret-pass')->create();
        $this->lawyer = User::factory()->role(Role::Associate)->create(['firm_id' => $this->firm->id]);
        $this->matter = Matter::factory()->for($this->client)->create(['responsible_lawyer_id' => $this->lawyer->id]);
    }

    public function test_a_client_and_lawyer_exchange_messages_with_unread_tracking(): void
    {
        $this->actingAs($this->client, 'client');
        $id = $this->postJson('/api/portal/message-threads', ['matter_id' => $this->matter->id, 'subject' => 'Hearing on Monday', 'body' => 'Do I need to bring the original deed?'])
            ->assertCreated()
            ->assertJsonPath('messages.0.mine', true)
            ->json('id');

        Notification::assertSentTo($this->lawyer, NewMessage::class);

        $this->actingAs($this->lawyer, 'web');
        $this->getJson('/api/v1/message-threads/unread-count')->assertJsonPath('count', 1);
        $this->getJson('/api/v1/message-threads')->assertJsonPath('data.0.unread_count', 1)->assertJsonPath('data.0.client.name', $this->client->name);
        $this->getJson("/api/v1/message-threads/{$id}")->assertOk()->assertJsonPath('messages.0.mine', false);
        $this->getJson('/api/v1/message-threads/unread-count')->assertJsonPath('count', 0);

        $this->postJson("/api/v1/message-threads/{$id}/messages", ['body' => 'Yes, and two photocopies.'])->assertCreated()->assertJsonCount(2, 'messages');
        Notification::assertSentTo($this->client, NewMessage::class);

        $this->actingAs($this->client, 'client');
        $this->getJson('/api/portal/message-threads')->assertJsonPath('unread', 1)->assertJsonMissingPath('data.0.client');
    }

    public function test_repeat_messages_alert_only_once_until_read(): void
    {
        $this->actingAs($this->client, 'client');
        $id = $this->postJson('/api/portal/message-threads', ['matter_id' => $this->matter->id, 'subject' => 'Update?', 'body' => 'Hello'])->json('id');
        $this->postJson("/api/portal/message-threads/{$id}/messages", ['body' => 'Anyone there?'])->assertCreated();

        Notification::assertSentToTimes($this->lawyer, NewMessage::class, 1);
    }

    public function test_client_attachments_become_shared_matter_files(): void
    {
        $this->actingAs($this->client, 'client');
        $thread = $this->postJson('/api/portal/message-threads', [
            'matter_id' => $this->matter->id, 'subject' => 'Receipts', 'body' => 'Attached.',
            'file' => UploadedFile::fake()->createWithContent('receipt.pdf', '%PDF-1.4 receipt'),
        ])->assertCreated()->assertJsonPath('messages.0.attachment.name', 'receipt.pdf')->json();

        $fileId = $thread['messages'][0]['attachment']['id'];
        $this->assertDatabaseHas('matter_files', ['id' => $fileId, 'shared_with_client' => true, 'uploaded_by_client_id' => $this->client->id, 'uploaded_by' => null]);
        $this->get("/api/portal/files/{$fileId}/download")->assertOk();
    }

    public function test_clients_only_see_their_own_threads(): void
    {
        $other = Client::factory()->for($this->firm)->withPortal('x')->create();
        $otherMatter = Matter::factory()->for($other)->create();

        $this->actingAs($this->client, 'client');
        $this->postJson('/api/portal/message-threads', ['matter_id' => $otherMatter->id, 'subject' => 'x', 'body' => 'x'])->assertNotFound();

        $this->actingAs($this->lawyer, 'web');
        $id = $this->postJson('/api/v1/message-threads', ['matter_id' => $otherMatter->id, 'subject' => 'Private', 'body' => 'For the other client'])->assertCreated()->json('id');

        $this->actingAs($this->client, 'client');
        $this->getJson("/api/portal/message-threads/{$id}")->assertNotFound();
        $this->getJson('/api/portal/message-threads')->assertJsonCount(0, 'data');
    }

    public function test_messages_cannot_be_edited_and_clients_without_portal_cannot_be_messaged(): void
    {
        $this->actingAs($this->lawyer, 'web');
        $this->postJson('/api/v1/message-threads', ['matter_id' => $this->matter->id, 'subject' => 'Hi', 'body' => 'Original'])->assertCreated();

        $this->expectException(LogicException::class);
        Message::first()->update(['body' => 'Rewritten']);
    }

    public function test_staff_cannot_start_a_thread_with_a_client_without_portal_access(): void
    {
        $this->client->update(['portal_enabled' => false]);
        $this->actingAs($this->lawyer, 'web');

        $this->postJson('/api/v1/message-threads', ['matter_id' => $this->matter->id, 'subject' => 'Hi', 'body' => 'x'])->assertStatus(422);
    }
}
