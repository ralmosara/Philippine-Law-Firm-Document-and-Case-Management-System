<?php

namespace Tests\Feature\Documents;

use App\Domain\Documents\Actions\CreateDocumentVersion;
use App\Domain\Documents\Enums\DocumentStatus;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Notifications\SignatureAnswered;
use App\Domain\Documents\Notifications\SignatureRequested;
use App\Domain\Matters\Models\Client;
use App\Domain\Matters\Models\Firm;
use App\Domain\Matters\Models\Matter;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ElectronicSignatureTest extends TestCase
{
    use RefreshDatabase;

    private Firm $firm;

    private Client $client;

    private Document $document;

    private User $lawyer;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->firm = Firm::factory()->create();
        $this->client = Client::factory()->for($this->firm)->withPortal('secret-pass')->create(['name' => 'Juan Dela Cruz']);
        $matter = Matter::factory()->for($this->client)->create();
        $this->lawyer = $this->signIn(Role::Associate, $this->firm);

        $this->document = Document::create(['firm_id' => $this->firm->id, 'matter_id' => $matter->id, 'title' => 'Retainer Agreement', 'created_by' => $this->lawyer->id]);
        app(CreateDocumentVersion::class)->execute($this->document, 'The client engages the firm...', $this->lawyer);
    }

    public function test_the_client_signs_in_the_portal_and_the_evidence_is_kept(): void
    {
        $this->postJson("/api/v1/documents/{$this->document->id}/signature-requests")
            ->assertStatus(422); // still a draft

        $this->document->forceFill(['status' => DocumentStatus::Final])->save();

        $id = $this->postJson("/api/v1/documents/{$this->document->id}/signature-requests", ['message' => 'Please sign by Friday'])
            ->assertCreated()
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('content_sha256', hash('sha256', 'The client engages the firm...'))
            ->json('id');

        $this->assertSame(DocumentStatus::PendingSignature, $this->document->fresh()->status);
        $this->assertTrue($this->document->fresh()->shared_with_client);
        Notification::assertSentTo($this->client, SignatureRequested::class);

        $this->actingAs($this->client, 'client');
        $this->getJson('/api/portal/signature-requests')->assertJsonCount(1, 'data')->assertJsonPath('data.0.document.title', 'Retainer Agreement');
        $this->getJson("/api/portal/signature-requests/{$id}")->assertJsonPath('content', 'The client engages the firm...');

        $this->postJson("/api/portal/signature-requests/{$id}/sign", ['signer_name' => 'Juan Dela Cruz', 'method' => 'typed'])
            ->assertStatus(422)->assertJsonValidationErrors('consent');

        $this->withHeader('User-Agent', 'TestBrowser/1.0')
            ->postJson("/api/portal/signature-requests/{$id}/sign", ['signer_name' => 'Juan Dela Cruz', 'method' => 'drawn', 'signature_image' => $this->png(), 'consent' => true])
            ->assertOk()->assertJsonPath('status', 'signed');

        // A second answer is refused.
        $this->postJson("/api/portal/signature-requests/{$id}/sign", ['signer_name' => 'Juan', 'method' => 'typed', 'consent' => true])->assertStatus(422);

        $this->assertSame(DocumentStatus::Signed, $this->document->fresh()->status);
        Notification::assertSentTo($this->lawyer, SignatureAnswered::class);

        $this->actingAs($this->lawyer);
        $this->getJson("/api/v1/documents/{$this->document->id}/signature-requests")
            ->assertJsonPath('0.status', 'signed')
            ->assertJsonPath('0.signer_name', 'Juan Dela Cruz')
            ->assertJsonPath('0.signature_method', 'drawn')
            ->assertJsonPath('0.signer_user_agent', 'TestBrowser/1.0')
            ->assertJsonPath('0.signer_ip', '127.0.0.1');
    }

    public function test_content_changed_after_the_request_cannot_be_signed(): void
    {
        $id = $this->requestSignature();

        DB::table('document_versions')->where('document_id', $this->document->id)->update(['content' => 'Altered terms']);

        $this->actingAs($this->client, 'client');
        $this->postJson("/api/portal/signature-requests/{$id}/sign", ['signer_name' => 'Juan', 'method' => 'typed', 'consent' => true])
            ->assertStatus(422)->assertJsonValidationErrors('document');
        $this->assertSame(DocumentStatus::PendingSignature, $this->document->fresh()->status);
    }

    public function test_declining_or_cancelling_returns_the_document_to_final(): void
    {
        $id = $this->requestSignature();

        $this->actingAs($this->client, 'client');
        $this->postJson("/api/portal/signature-requests/{$id}/decline", ['reason' => 'Clause 4 is wrong'])->assertOk();
        $this->assertSame(DocumentStatus::Final, $this->document->fresh()->status);

        $this->actingAs($this->lawyer);
        $again = $this->postJson("/api/v1/documents/{$this->document->id}/signature-requests")->assertCreated()->json('id');
        $this->postJson("/api/v1/signature-requests/{$again}/cancel")->assertOk()->assertJsonPath('status', 'cancelled');
        $this->assertSame(DocumentStatus::Final, $this->document->fresh()->status);
    }

    public function test_expired_requests_cannot_be_signed(): void
    {
        $id = $this->requestSignature();
        $this->travel(15)->days();

        $this->actingAs($this->client, 'client');
        $this->getJson('/api/portal/signature-requests')->assertJsonCount(0, 'data');
        $this->postJson("/api/portal/signature-requests/{$id}/sign", ['signer_name' => 'Juan', 'method' => 'typed', 'consent' => true])
            ->assertStatus(422);
    }

    public function test_only_the_addressed_client_can_answer(): void
    {
        $id = $this->requestSignature();
        $other = Client::factory()->for($this->firm)->withPortal('x')->create();

        $this->actingAs($other, 'client');
        $this->getJson("/api/portal/signature-requests/{$id}")->assertNotFound();
        $this->postJson("/api/portal/signature-requests/{$id}/sign", ['signer_name' => 'X', 'method' => 'typed', 'consent' => true])->assertNotFound();
    }

    public function test_a_pending_document_cannot_be_marked_signed_by_hand(): void
    {
        $this->requestSignature();

        $this->postJson("/api/v1/documents/{$this->document->id}/status", ['status' => 'signed'])->assertStatus(422);
    }

    public function test_the_drawn_signature_must_be_a_real_png(): void
    {
        $id = $this->requestSignature();

        $this->actingAs($this->client, 'client');
        $this->postJson("/api/portal/signature-requests/{$id}/sign", [
            'signer_name' => 'Juan', 'method' => 'drawn', 'consent' => true,
            'signature_image' => 'data:image/png;base64,'.base64_encode('<svg onload=alert(1)>'),
        ])->assertStatus(422)->assertJsonValidationErrors('signature_image');
    }

    private function requestSignature(): int
    {
        $this->document->forceFill(['status' => DocumentStatus::Final])->save();

        return $this->postJson("/api/v1/documents/{$this->document->id}/signature-requests")->assertCreated()->json('id');
    }

    private function png(): string
    {
        $image = imagecreatetruecolor(20, 10);
        ob_start();
        imagepng($image);

        return 'data:image/png;base64,'.base64_encode(ob_get_clean());
    }
}
