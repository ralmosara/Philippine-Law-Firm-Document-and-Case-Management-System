<?php

namespace Tests\Feature\Portal;

use App\Domain\Documents\Requests\DocumentRequest;
use App\Domain\Documents\Requests\Notifications\DocumentsRequested;
use App\Domain\Documents\Requests\Notifications\DocumentUploaded;
use App\Domain\Matters\Enums\MatterStatus;
use App\Domain\Matters\Models\Client;
use App\Domain\Matters\Models\Firm;
use App\Domain\Matters\Models\Matter;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PortalLanguageTest extends TestCase
{
    use RefreshDatabase;

    private Firm $firm;

    private User $lawyer;

    private Client $client;

    private Matter $matter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->firm = Firm::factory()->create(['name' => 'Reyes Law', 'dpo_name' => 'Atty. Ana Reyes']);
        $this->lawyer = $this->signIn(Role::Partner, $this->firm);
        $this->client = Client::factory()->for($this->firm)->withPortal('secret-pass')->create(['name' => 'Juan Dela Cruz', 'email' => 'juan@example.com']);
        $this->matter = Matter::factory()->for($this->client)->create(['case_type' => 'Civil', 'status' => MatterStatus::PreTrial, 'responsible_lawyer_id' => $this->lawyer->id]);
    }

    public function test_a_client_switches_the_portal_to_filipino_and_it_answers_in_filipino(): void
    {
        $this->actingAs($this->client, 'client');
        $this->getJson('/api/portal/me')->assertJsonPath('client.locale', 'en');
        $this->getJson('/api/portal/matters')->assertJsonPath('data.0.case_type', 'Civil')->assertJsonPath('data.0.status_label', 'Pre-Trial');

        $this->putJson('/api/portal/locale', ['locale' => 'fil'])->assertOk()->assertJsonPath('client.locale', 'fil');
        $this->assertSame('fil', $this->client->fresh()->locale);

        $this->getJson('/api/portal/matters')->assertJsonPath('data.0.case_type', 'Sibil');
        $this->getJson("/api/portal/matters/{$this->matter->id}")->assertJsonPath('case_type', 'Sibil');
        $this->putJson('/api/portal/locale', ['locale' => 'es'])->assertStatus(422)->assertJsonValidationErrors('locale');
    }

    public function test_the_privacy_notice_comes_in_filipino_unless_the_firm_wrote_only_an_english_one(): void
    {
        $this->client->forceFill(['locale' => 'fil'])->save();
        $this->actingAs($this->client, 'client');

        $privacy = $this->getJson('/api/portal/privacy')->assertOk();
        $this->assertStringContainsString('Kinokolekta at ginagamit ng Reyes Law', $privacy->json('notice'));
        $this->assertStringContainsString('kay Atty. Ana Reyes, ang aming Data Protection Officer', $privacy->json('notice'));
        $this->assertSame('Makita ang kopya ng aking datos', $privacy->json('request_types.0.label'));

        // The firm's own English notice: a stock translation would not match it, so English it is.
        $this->firm->forceFill(['privacy_notice' => 'Our own notice.'])->save();
        $this->getJson('/api/portal/privacy')->assertJsonPath('notice', 'Our own notice.');

        // Until the firm adds its own Filipino version.
        $this->firm->forceFill(['privacy_notice_fil' => 'Ang aming sariling abiso.'])->save();
        $this->getJson('/api/portal/privacy')->assertJsonPath('notice', 'Ang aming sariling abiso.');
    }

    public function test_a_new_filipino_notice_is_a_new_version_for_clients_to_accept(): void
    {
        $this->signIn(Role::ManagingPartner, $this->firm);
        $version = $this->firm->fresh()->privacy_notice_version;

        $this->putJson('/api/v1/privacy/settings', ['retention_years' => 10, 'privacy_notice_fil' => 'Bagong abiso.'])
            ->assertOk()->assertJsonPath('privacy_notice_fil', 'Bagong abiso.')->assertJsonPath('privacy_notice_version', $version + 1);
        $this->putJson('/api/v1/privacy/settings', ['retention_years' => 10, 'privacy_notice_fil' => 'Bagong abiso.'])
            ->assertJsonPath('privacy_notice_version', $version + 1);
    }

    public function test_sign_in_errors_follow_the_language_the_portal_asks_for(): void
    {
        $this->postJson('/api/portal/login', ['email' => 'juan@example.com', 'password' => 'wrong'], ['X-Locale' => 'fil'])
            ->assertStatus(422)->assertJsonPath('errors.email.0', 'Hindi tugma ang email at password sa aming rekord.');
        $this->postJson('/api/portal/login', ['email' => '', 'password' => 'x'], ['X-Locale' => 'fil'])
            ->assertJsonPath('errors.email.0', 'Kailangan ang email.');
        $this->postJson('/api/portal/login', ['email' => 'juan@example.com', 'password' => 'wrong'], ['X-Locale' => 'xx'])
            ->assertJsonPath('errors.email.0', 'These credentials do not match our records.');
    }

    public function test_emails_go_to_the_client_in_their_language_and_to_staff_in_english(): void
    {
        Notification::fake();
        Storage::fake('local');

        // Staff set the language when giving portal access.
        $this->putJson("/api/v1/clients/{$this->client->id}/portal-access", ['portal_enabled' => true, 'locale' => 'fil'])
            ->assertOk()->assertJsonPath('portal_locale', 'fil');

        $id = $this->postJson("/api/v1/matters/{$this->matter->id}/document-requests", ['title' => 'Mga dokumento', 'items' => [['label' => 'Valid ID']]])->assertCreated()->json('id');
        Notification::assertSentTo($this->client, DocumentsRequested::class, fn ($notification, $channels, $notifiable, $locale) => $locale === 'fil');

        $mail = new DocumentsRequested(DocumentRequest::find($id));
        app()->setLocale('fil');
        $rendered = $mail->toMail($this->client);
        app()->setLocale('en');
        $this->assertSame('Mga dokumentong kailangan: Mga dokumento', $rendered->subject);
        $this->assertSame('Mahal na Juan Dela Cruz,', $rendered->greeting);

        // The client uploads in Filipino; the lawyer's alert is still in English.
        $this->actingAs($this->client, 'client');
        $item = $this->getJson('/api/portal/document-requests')->json('0.items.0.id');
        $this->post("/api/portal/document-request-items/{$item}/upload", ['file' => UploadedFile::fake()->create('id.pdf', 50, 'application/pdf')], ['Accept' => 'application/json'])->assertOk();
        Notification::assertSentTo($this->lawyer, DocumentUploaded::class, fn ($notification, $channels, $notifiable, $locale) => $locale === 'en');
    }
}
