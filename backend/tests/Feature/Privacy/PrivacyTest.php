<?php

namespace Tests\Feature\Privacy;

use App\Domain\Billing\Models\TimeEntry;
use App\Domain\Billing\Services\InvoiceGenerator;
use App\Domain\Documents\Actions\StoreMatterFile;
use App\Domain\Matters\Models\Client;
use App\Domain\Matters\Models\Firm;
use App\Domain\Matters\Models\Matter;
use App\Domain\Messaging\Models\MessageThread;
use App\Domain\Privacy\Models\DataSubjectRequest;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PrivacyTest extends TestCase
{
    use RefreshDatabase;

    private Firm $firm;

    private Client $client;

    private User $partner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->firm = Firm::factory()->create(['name' => 'Santos & Reyes Law', 'email' => 'office@santosreyes.ph']);
        $this->client = Client::factory()->for($this->firm)->withPortal('secret-pass')->create(['name' => 'Juan Dela Cruz', 'email' => 'juan@example.com', 'phone' => '0917 555 0101']);
        $this->partner = $this->signIn(Role::ManagingPartner, $this->firm);
    }

    public function test_the_notice_is_versioned_and_portal_clients_accept_each_version(): void
    {
        $this->actingAs($this->client, 'client');
        $this->getJson('/api/portal/privacy')
            ->assertOk()
            ->assertJsonPath('needs_acceptance', true)
            ->assertJsonPath('version', 1)
            ->assertJsonPath('dpo.email', 'office@santosreyes.ph')
            ->assertSee('Data Privacy Act of 2012');

        $this->postJson('/api/portal/privacy/accept')->assertOk()->assertJsonPath('needs_acceptance', false)->assertJsonPath('accepted_version', 1);

        // The firm names its DPO: the built-in notice changes, so it is a new version.
        $this->actingAs($this->partner);
        $this->putJson('/api/v1/privacy/settings', ['dpo_name' => 'Atty. Ana Reyes', 'dpo_email' => 'dpo@santosreyes.ph', 'retention_years' => 10])
            ->assertOk()->assertJsonPath('privacy_notice_version', 2);
        $this->putJson('/api/v1/privacy/settings', ['dpo_name' => 'Atty. Ana Reyes', 'dpo_email' => 'dpo@santosreyes.ph', 'retention_years' => 10])
            ->assertJsonPath('privacy_notice_version', 2); // unchanged text: same version
        $this->getJson('/api/v1/privacy/summary')->assertJsonPath('clients_without_consent', 1);

        $this->actingAs($this->client, 'client');
        $this->getJson('/api/portal/privacy')->assertJsonPath('needs_acceptance', true)->assertSee('Atty. Ana Reyes, our Data Protection Officer');
    }

    public function test_the_public_intake_form_shows_the_notice_and_records_its_version(): void
    {
        $this->firm->update(['slug' => 'santos-reyes', 'intake_enabled' => true]);
        $this->getJson('/api/public/intake/santos-reyes')->assertOk()->assertJsonPath('privacy_notice', fn ($n) => str_contains($n, 'Republic Act No. 10173'));

        $this->postJson('/api/public/intake/santos-reyes', [
            'name' => 'Maria Clara', 'email' => 'maria@example.com', 'client_type' => 'individual', 'case_type' => 'Family',
            'description' => 'I need advice on a custody arrangement for my children.', 'consent' => true,
        ])->assertCreated();

        $this->assertDatabaseHas('intake_requests', ['email' => 'maria@example.com', 'privacy_notice_version' => 1]);
    }

    public function test_a_client_asks_through_the_portal_and_the_firm_answers_on_time(): void
    {
        $this->actingAs($this->client, 'client');
        $this->postJson('/api/portal/privacy/requests', ['type' => 'correction'])->assertStatus(422)->assertJsonValidationErrors('details');
        $this->postJson('/api/portal/privacy/requests', ['type' => 'correction', 'details' => 'My mobile is now 0918 555 0199.'])
            ->assertCreated()
            ->assertJsonPath('requests.0.status', 'open')
            ->assertJsonPath('requests.0.due_on', today()->addDays(DataSubjectRequest::RESPONSE_DAYS)->toDateString());

        $this->actingAs($this->partner);
        $id = $this->getJson('/api/v1/privacy/requests')->assertOk()->assertJsonPath('0.source', 'portal')->json('0.id');
        $this->getJson('/api/v1/privacy/summary')->assertJsonPath('open_requests', 1)->assertJsonPath('overdue_requests', 0);

        $this->travel(DataSubjectRequest::RESPONSE_DAYS + 1)->days();
        $this->getJson('/api/v1/privacy/requests')->assertJsonPath('0.is_overdue', true);
        $this->getJson('/api/v1/privacy/summary')->assertJsonPath('overdue_requests', 1);

        $this->postJson("/api/v1/privacy/requests/{$id}/resolve", ['status' => 'completed', 'resolution' => 'Mobile number updated.'])
            ->assertOk()->assertJsonPath('status', 'completed');
        $this->postJson("/api/v1/privacy/requests/{$id}/resolve", ['status' => 'denied', 'resolution' => 'x'])->assertStatus(422);

        $this->actingAs($this->client, 'client');
        $this->getJson('/api/portal/privacy')->assertJsonPath('requests.0.resolution', 'Mobile number updated.');
    }

    public function test_the_personal_data_export_covers_what_the_firm_holds(): void
    {
        $matter = Matter::factory()->for($this->client)->create(['title' => 'Dela Cruz v. Reyes']);
        TimeEntry::factory()->for($matter)->create(['user_id' => $this->partner->id]);
        $generator = app(InvoiceGenerator::class);
        $generator->issue($generator->generateForMatter($matter, $this->partner));
        MessageThread::create(['firm_id' => $this->firm->id, 'client_id' => $this->client->id, 'matter_id' => $matter->id, 'subject' => 'Hearing'])
            ->messages()->create(['firm_id' => $this->firm->id, 'sender_type' => 'client', 'sender_id' => $this->client->id, 'body' => 'Will I need to testify?']);

        $response = $this->get("/api/v1/clients/{$this->client->id}/personal-data")->assertOk()->assertHeader('Content-Type', 'application/json; charset=UTF-8');
        $data = json_decode($response->streamedContent(), true);

        $this->assertSame('juan@example.com', $data['profile']['email']);
        $this->assertSame('Dela Cruz v. Reyes', $data['matters'][0]['title']);
        $this->assertCount(1, $data['invoices']);
        $this->assertSame('Will I need to testify?', $data['messages'][0]['messages'][0]['text']);
        $this->assertSame('you', $data['messages'][0]['messages'][0]['from']);
        $this->assertSame('Santos & Reyes Law', $data['controller']['name']);

        $this->signIn(Role::Associate, $this->firm);
        $this->get("/api/v1/clients/{$this->client->id}/personal-data")->assertForbidden();
    }

    public function test_anonymizing_waits_for_open_business_then_removes_contact_details(): void
    {
        $matter = Matter::factory()->for($this->client)->create();

        $this->postJson("/api/v1/clients/{$this->client->id}/anonymize", ['confirm' => true])
            ->assertStatus(422)->assertJsonValidationErrors(['client' => 'Cannot anonymize yet: the client has matters that are not closed.']);

        $matter->forceFill(['status' => 'closed', 'closed_at' => today()])->save();
        $this->postJson("/api/v1/clients/{$this->client->id}/anonymize")->assertStatus(422); // must confirm
        $this->postJson("/api/v1/clients/{$this->client->id}/anonymize", ['confirm' => true])->assertOk()->assertJsonPath('name', "Former client #{$this->client->id}");

        $fresh = $this->client->fresh();
        $this->assertNull($fresh->email);
        $this->assertNull($fresh->phone);
        $this->assertFalse($fresh->portal_enabled);
        $this->assertNotNull($fresh->anonymized_at);
        $this->assertTrue($matter->fresh()->exists); // the case record is kept

        // The audit trail no longer holds the old details.
        $this->assertDatabaseMissing('audit_logs', ['subject_type' => 'client', 'subject_id' => $this->client->id, 'changes' => json_encode(['email' => 'juan@example.com'])]);
        $this->assertSame(0, AuditLog::where('subject_type', 'client')->where('subject_id', $this->client->id)->where('changes', 'like', '%juan@example.com%')->count());
        $this->assertDatabaseHas('audit_logs', ['subject_type' => 'client', 'subject_id' => $this->client->id, 'action' => 'anonymized']);
    }

    public function test_matters_past_retention_are_listed_and_disposed_of_securely(): void
    {
        Storage::fake('local');
        $old = Matter::factory()->for($this->client)->create(['title' => 'Old case']);
        $recent = Matter::factory()->for($this->client)->create(['title' => 'Recent case']);
        $file = app(StoreMatterFile::class)->execute($old, UploadedFile::fake()->createWithContent('affidavit.txt', 'Sworn statement'), $this->partner);
        $old->forceFill(['status' => 'closed', 'closed_at' => today()->subYears(11)])->save();
        $recent->forceFill(['status' => 'closed', 'closed_at' => today()->subYears(2)])->save();

        $this->getJson('/api/v1/privacy/retention')
            ->assertOk()
            ->assertJsonPath('retention_years', 10)
            ->assertJsonCount(1, 'matters')
            ->assertJsonPath('matters.0.title', 'Old case')
            ->assertJsonPath('matters.0.files', 1);

        $this->postJson("/api/v1/matters/{$recent->id}/dispose", ['confirm' => true])->assertStatus(422);
        $this->postJson("/api/v1/matters/{$old->id}/dispose", ['confirm' => true])->assertOk()->assertJsonPath('files', 1);

        Storage::disk('local')->assertMissing($file->path);
        $this->assertDatabaseMissing('matter_files', ['id' => $file->id]);
        $this->assertSoftDeleted('matters', ['id' => $old->id]);
        $this->assertNotNull(Matter::withTrashed()->find($old->id)->disposed_at);
        $this->assertDatabaseHas('audit_logs', ['subject_type' => 'matter', 'subject_id' => $old->id, 'action' => 'disposed']);
        $this->getJson('/api/v1/privacy/retention')->assertJsonCount(0, 'matters');
    }

    public function test_breaches_track_the_72_hour_npc_deadline(): void
    {
        $discovered = now()->subHours(10);
        $id = $this->postJson('/api/v1/privacy/incidents', [
            'title' => 'Laptop stolen from a car',
            'description' => 'An associate\'s laptop with client files was stolen in Makati.',
            'discovered_at' => $discovered->toIso8601String(),
            'affected_count' => 40,
            'data_involved' => 'Names, case details, IDs',
            'sensitive' => true,
            'notifiable' => true,
        ])->assertCreated()
            ->assertJsonPath('npc_notification_pending', true)
            ->assertJsonPath('notify_by', $discovered->copy()->addHours(72)->startOfSecond()->toIso8601String())
            ->json('id');

        $this->getJson('/api/v1/privacy/summary')->assertJsonPath('incidents_awaiting_npc', 1);

        $this->patchJson("/api/v1/privacy/incidents/{$id}", ['npc_notified_at' => now()->toIso8601String(), 'status' => 'contained', 'actions_taken' => 'Remote wipe; passwords rotated.'])
            ->assertOk()->assertJsonPath('npc_notification_pending', false)->assertJsonPath('status', 'contained');
        $this->getJson('/api/v1/privacy/summary')->assertJsonPath('incidents_awaiting_npc', 0);
        $this->assertDatabaseHas('audit_logs', ['subject_type' => 'privacy_incident', 'subject_id' => $id, 'action' => 'updated']);
    }

    public function test_only_firm_managers_see_privacy_records_and_only_their_own_firm(): void
    {
        $this->actingAs($this->client, 'client');
        $this->postJson('/api/portal/privacy/requests', ['type' => 'access'])->assertCreated();

        $this->signIn(Role::Associate, $this->firm);
        $this->getJson('/api/v1/privacy/requests')->assertForbidden();

        $this->signIn(Role::ManagingPartner, Firm::factory()->create());
        $this->getJson('/api/v1/privacy/requests')->assertOk()->assertJsonCount(0);
        $this->get("/api/v1/clients/{$this->client->id}/personal-data")->assertNotFound();
    }
}
