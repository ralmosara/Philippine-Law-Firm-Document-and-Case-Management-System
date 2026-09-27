<?php

namespace Tests\Feature\Documents;

use App\Domain\Documents\Requests\DocumentRequests;
use App\Domain\Documents\Requests\Notifications\DocumentReturned;
use App\Domain\Documents\Requests\Notifications\DocumentsReminder;
use App\Domain\Documents\Requests\Notifications\DocumentsRequested;
use App\Domain\Documents\Requests\Notifications\DocumentUploaded;
use App\Domain\Matters\Models\Client;
use App\Domain\Matters\Models\Firm;
use App\Domain\Matters\Models\Matter;
use App\Enums\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentRequestTest extends TestCase
{
    use RefreshDatabase;

    private Firm $firm;

    private User $lawyer;

    private Client $client;

    private Matter $matter;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Notification::fake();
        $this->firm = Firm::factory()->create();
        $this->lawyer = $this->signIn(Role::Partner, $this->firm);
        $this->client = Client::factory()->for($this->firm)->withPortal('secret-pass')->create(['name' => 'Juan Dela Cruz', 'email' => 'juan@example.com']);
        $this->matter = Matter::factory()->for($this->client)->create(['responsible_lawyer_id' => $this->lawyer->id]);
    }

    private function sendRequest(array $overrides = []): int
    {
        return $this->postJson("/api/v1/matters/{$this->matter->id}/document-requests", [
            'title' => 'Documents for the complaint',
            'message' => 'Clear photos are fine.',
            'due_on' => today()->addDays(7)->toDateString(),
            'items' => [
                ['label' => 'Valid government ID'],
                ['label' => 'Lease contract'],
                ['label' => 'Photos of the property', 'required' => false],
            ],
            ...$overrides,
        ])->assertCreated()->json('id');
    }

    public function test_a_request_goes_to_the_client_who_uploads_and_the_lawyer_reviews(): void
    {
        $id = $this->sendRequest();
        Notification::assertSentTo($this->client, DocumentsRequested::class);

        // The client sees it in the portal and uploads the ID.
        $this->actingAs($this->client, 'client');
        $request = $this->getJson('/api/portal/document-requests')->assertOk()->assertJsonPath('0.progress.total', 2)->json('0');
        $idItem = $request['items'][0]['id'];

        $this->post("/api/portal/document-request-items/{$idItem}/upload", ['file' => UploadedFile::fake()->create('passport.pdf', 200, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('items.0.status', 'uploaded')->assertJsonPath('items.0.file.name', 'passport.pdf');
        $this->assertDatabaseHas('matter_files', ['matter_id' => $this->matter->id, 'original_name' => 'passport.pdf', 'uploaded_by_client_id' => $this->client->id, 'shared_with_client' => true]);
        Notification::assertSentTo($this->lawyer, DocumentUploaded::class);

        // The lawyer sends it back; the client is told why.
        $this->signIn(Role::Partner, $this->firm); // a colleague reviews
        $this->postJson("/api/v1/document-request-items/{$idItem}/review", ['decision' => 'reject'])->assertStatus(422)->assertJsonValidationErrors('note');
        $this->postJson("/api/v1/document-request-items/{$idItem}/review", ['decision' => 'reject', 'note' => 'The ID has expired.'])
            ->assertOk()->assertJsonPath('items.0.status', 'rejected');
        Notification::assertSentTo($this->client, DocumentReturned::class);

        // Uploaded again and accepted, then the contract: the request completes (photos are optional).
        $this->actingAs($this->client, 'client');
        $this->post("/api/portal/document-request-items/{$idItem}/upload", ['file' => UploadedFile::fake()->create('new-id.jpg', 100, 'image/jpeg')], ['Accept' => 'application/json'])->assertOk();
        $this->post('/api/portal/document-request-items/'.$request['items'][1]['id'].'/upload', ['file' => UploadedFile::fake()->create('lease.pdf', 300, 'application/pdf')], ['Accept' => 'application/json'])->assertOk();

        $this->actingAs($this->lawyer, 'web')->actingAs($this->lawyer, 'sanctum');
        $this->postJson("/api/v1/document-request-items/{$idItem}/review", ['decision' => 'accept'])->assertOk()->assertJsonPath('status', 'open');
        $this->postJson('/api/v1/document-request-items/'.$request['items'][1]['id'].'/review', ['decision' => 'accept'])
            ->assertOk()->assertJsonPath('status', 'completed')->assertJsonPath('progress.done', 2);

        $this->getJson("/api/v1/matters/{$this->matter->id}/document-requests")->assertJsonPath('0.id', $id)->assertJsonPath('0.status', 'completed');
    }

    public function test_clients_need_portal_access_and_only_see_their_own_requests(): void
    {
        $this->client->update(['portal_enabled' => false]);
        $this->postJson("/api/v1/matters/{$this->matter->id}/document-requests", ['title' => 'X', 'items' => [['label' => 'ID']]])
            ->assertStatus(422)->assertJsonValidationErrors('client');
        $this->client->update(['portal_enabled' => true]);

        $id = $this->sendRequest();
        $item = $this->getJson("/api/v1/matters/{$this->matter->id}/document-requests")->json('0.items.0.id');

        $other = Client::factory()->for($this->firm)->withPortal('x')->create();
        $this->actingAs($other, 'client');
        $this->getJson('/api/portal/document-requests')->assertJsonCount(0);
        $this->getJson("/api/portal/document-requests/{$id}")->assertNotFound();
        $this->post("/api/portal/document-request-items/{$item}/upload", ['file' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])->assertNotFound();
    }

    public function test_cancelled_requests_take_no_uploads(): void
    {
        $id = $this->sendRequest();
        $item = $this->getJson("/api/v1/matters/{$this->matter->id}/document-requests")->json('0.items.0.id');
        $this->postJson("/api/v1/document-requests/{$id}/cancel")->assertOk()->assertJsonPath('status', 'cancelled');

        $this->actingAs($this->client, 'client');
        $this->getJson('/api/portal/document-requests')->assertJsonCount(0);
        $this->post("/api/portal/document-request-items/{$item}/upload", ['file' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])->assertStatus(422);
    }

    public function test_reminders_go_out_before_the_due_date_and_once_overdue(): void
    {
        $this->sendRequest(['due_on' => today()->addDays(5)->toDateString()]);
        $service = app(DocumentRequests::class);

        $this->assertSame(0, $service->sendReminders(CarbonImmutable::today()));               // 5 days out: too early
        $this->assertSame(1, $service->sendReminders(CarbonImmutable::today()->addDays(3)));   // 2 days before
        $this->assertSame(0, $service->sendReminders(CarbonImmutable::today()->addDays(4)));   // not twice
        $this->assertSame(1, $service->sendReminders(CarbonImmutable::today()->addDays(6)));   // overdue
        $this->assertSame(0, $service->sendReminders(CarbonImmutable::today()->addDays(20)));  // overdue only once

        Notification::assertSentToTimes($this->client, DocumentsReminder::class, 2);
        Notification::assertSentTo($this->client, DocumentsReminder::class, fn ($n) => $n->missing === ['Valid government ID', 'Lease contract']);
    }
}
