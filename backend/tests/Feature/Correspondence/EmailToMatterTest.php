<?php

namespace Tests\Feature\Correspondence;

use App\Domain\Correspondence\InboundEmails;
use App\Domain\Correspondence\Models\MatterEmail;
use App\Domain\Correspondence\Notifications\EmailNeedsReview;
use App\Domain\Documents\Models\MatterFile;
use App\Domain\Documents\Scanning\ScannerUnavailable;
use App\Domain\Documents\Scanning\ScanResult;
use App\Domain\Documents\Scanning\VirusScanner;
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

class EmailToMatterTest extends TestCase
{
    use RefreshDatabase;

    private Firm $firm;

    private User $lawyer;

    private Matter $matter;

    private string $address;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['services.inbound_email.address' => 'files@inbound.santoslaw.ph', 'services.inbound_email.secret' => 's3cret-value', 'services.inbound_email.authserv_id' => 'mx.inbound.test']);
        $this->firm = Firm::factory()->create();
        $this->lawyer = $this->signIn(Role::Associate, $this->firm, ['email' => 'ana@santoslaw.ph']);
        $client = Client::factory()->for($this->firm)->create(['email' => 'juan@example.com']);
        $this->matter = Matter::factory()->for($client)->create(['firm_id' => $this->firm->id, 'responsible_lawyer_id' => $this->lawyer->id]);
        $this->address = $this->getJson("/api/v1/matters/{$this->matter->id}/emails")->assertOk()->json('address');
    }

    /** $auth: the provider's verdict on the sender, as its Authentication-Results header records it (null: none). */
    private function mime(string $from, string $to, string $subject = 'Notice of hearing', array $attachments = [], string $id = '<abc123@mail.example.com>', ?string $auth = 'mx.inbound.test; dmarc=pass'): string
    {
        $boundary = 'b1_'.md5($subject);
        $parts = "--{$boundary}\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nPlease see the attached notice.\r\nHearing on 12 October.\r\n";
        foreach ($attachments as $name => $content) {
            $parts .= "--{$boundary}\r\nContent-Type: application/octet-stream; name=\"{$name}\"\r\nContent-Disposition: attachment; filename=\"{$name}\"\r\nContent-Transfer-Encoding: base64\r\n\r\n".chunk_split(base64_encode($content))."\r\n";
        }

        return ($auth ? "Authentication-Results: {$auth}\r\n" : '')."From: {$from}\r\nTo: {$to}\r\nSubject: {$subject}\r\nDate: Mon, 28 Sep 2026 09:15:00 +0800\r\nMessage-ID: {$id}\r\nMIME-Version: 1.0\r\n"
            ."Content-Type: multipart/mixed; boundary=\"{$boundary}\"\r\n\r\n{$parts}--{$boundary}--\r\n";
    }

    private function deliver(string $raw, string $secret = 's3cret-value')
    {
        return $this->call('POST', '/api/webhooks/inbound-email', [], [], [], ['CONTENT_TYPE' => 'message/rfc822', 'PHP_AUTH_USER' => 'inbound', 'PHP_AUTH_PW' => $secret], $raw);
    }

    public function test_each_matter_has_a_private_address(): void
    {
        $this->assertMatchesRegularExpression('/^files\+[a-z0-9-]+\.[a-z0-9]{10}@inbound\.santoslaw\.ph$/', $this->address);
        $this->assertSame($this->address, $this->getJson("/api/v1/matters/{$this->matter->id}/emails")->json('address'));

        $new = $this->postJson("/api/v1/matters/{$this->matter->id}/emails/address")->assertOk()->json('address');
        $this->assertNotSame($this->address, $new);
        // Mail to the old address is no longer filed.
        $this->deliver($this->mime('Ana <ana@santoslaw.ph>', $this->address))->assertOk()->assertJsonPath('filed', 0);
    }

    public function test_mail_from_staff_is_filed_with_its_attachments(): void
    {
        $raw = $this->mime('Atty. Ana Santos <ana@santoslaw.ph>', "Clerk <clerk@court.gov.ph>, {$this->address}", 'Fwd: Notice of hearing', [
            'notice.pdf' => '%PDF-1.4 notice', 'setup.exe' => 'MZ binary',
        ]);

        $this->deliver($raw)->assertOk()->assertJsonPath('filed', 1);

        $email = MatterEmail::sole();
        $this->assertSame(MatterEmail::FILED, $email->status);
        $this->assertSame('Fwd: Notice of hearing', $email->subject);
        $this->assertSame($this->lawyer->id, $email->sender_user_id);
        $this->assertStringContainsString('Hearing on 12 October.', $email->body_text);
        $this->assertNull($email->raw_path);

        $this->assertSame('Fwd Notice of hearing.eml', MatterFile::find($email->eml_file_id)->original_name);
        $this->assertNotNull($email->attachments[0]['file_id']);
        $this->assertSame('notice.pdf', MatterFile::find($email->attachments[0]['file_id'])->original_name);
        $this->assertSame('File type not accepted', $email->attachments[1]['skipped']);
        $this->assertSame(2, MatterFile::where('matter_id', $this->matter->id)->count());

        // The same message delivered again is not filed twice.
        $this->deliver($raw)->assertOk()->assertJsonPath('filed', 0);
        $this->assertSame(1, MatterEmail::count());
    }

    public function test_mail_from_the_client_is_filed_and_strangers_wait_for_review(): void
    {
        Notification::fake();
        $this->deliver($this->mime('Juan <JUAN@example.com>', $this->address, 'My documents', [], '<c1@example.com>'))->assertOk();
        $this->assertSame(MatterEmail::FILED, MatterEmail::where('message_id', 'c1@example.com')->sole()->status);

        $this->deliver($this->mime('Promo <deals@spam.example>', $this->address, 'Win big', ['a.pdf' => '%PDF'], '<s1@spam.example>'))->assertOk();
        $held = MatterEmail::where('message_id', 's1@spam.example')->sole();
        $this->assertSame(MatterEmail::REVIEW, $held->status);
        $this->assertNull($held->eml_file_id);
        Notification::assertSentTo($this->lawyer, EmailNeedsReview::class);

        $this->postJson("/api/v1/matter-emails/{$held->id}/reject")->assertOk()->assertJsonPath('status', 'rejected');
        $this->assertNull($held->refresh()->body_text);
        $this->assertSame([], Storage::disk('local')->files("firms/{$this->firm->id}/inbound"));

        // Accepting a held email files it under the reviewer.
        $this->deliver($this->mime('Opposing Counsel <atty@other.ph>', $this->address, 'Comment on the motion', [], '<o1@other.ph>'))->assertOk();
        $other = MatterEmail::where('message_id', 'o1@other.ph')->sole();
        $this->postJson("/api/v1/matter-emails/{$other->id}/accept")->assertOk()->assertJsonPath('status', 'filed');
        $this->assertSame($this->lawyer->name, $this->getJson("/api/v1/matter-emails/{$other->id}")->json('reviewed_by'));
    }

    public function test_a_forged_from_line_does_not_bypass_review(): void
    {
        // No verdict from our provider: the From line alone proves nothing.
        $this->deliver($this->mime('Ana <ana@santoslaw.ph>', $this->address, 'Unverified', [], '<u1@x>', null))->assertOk();
        // A verdict planted by the sender, under another server's name.
        $this->deliver($this->mime('Ana <ana@santoslaw.ph>', $this->address, 'Planted', [], '<u2@x>', 'mx.attacker.example; dmarc=pass'))->assertOk();
        // Our provider says the checks failed.
        $this->deliver($this->mime('Ana <ana@santoslaw.ph>', $this->address, 'Failed', [], '<u3@x>', 'mx.inbound.test; spf=fail; dkim=fail; dmarc=fail'))->assertOk();
        // DKIM passed, but for someone else's domain.
        $this->deliver($this->mime('Ana <ana@santoslaw.ph>', $this->address, 'Misaligned', [], '<u4@x>', 'mx.inbound.test; dkim=pass header.d=bulkmailer.example'))->assertOk();

        foreach (['u1@x', 'u2@x', 'u3@x', 'u4@x'] as $id) {
            $email = MatterEmail::where('message_id', $id)->sole();
            $this->assertSame(MatterEmail::REVIEW, $email->status, $id);
            $this->assertNull($email->sender_user_id, $id);
        }

        // A DKIM pass for the From domain verifies the sender.
        $this->deliver($this->mime('Ana <ana@santoslaw.ph>', $this->address, 'Aligned', [], '<v1@x>', 'mx.inbound.test; spf=neutral; dkim=pass header.d=santoslaw.ph'))->assertOk();
        $this->assertSame(MatterEmail::FILED, MatterEmail::where('message_id', 'v1@x')->sole()->status);

        // SendGrid posts its DKIM results as a field.
        $this->post('/api/webhooks/inbound-email', ['email' => $this->mime('Ana <ana@santoslaw.ph>', $this->address, 'SendGrid', [], '<v2@x>', null), 'dkim' => '{@santoslaw.ph : pass}'], ['X-Inbound-Secret' => 's3cret-value'])->assertOk();
        $this->assertSame(MatterEmail::FILED, MatterEmail::where('message_id', 'v2@x')->sole()->status);
    }

    public function test_filing_waits_for_the_virus_scanner_and_resumes(): void
    {
        $down = true;
        $this->app->instance(VirusScanner::class, new class($down) implements VirusScanner
        {
            public function __construct(public bool &$down) {}

            public function scan(string $path): ScanResult
            {
                if ($this->down) {
                    throw new ScannerUnavailable('down');
                }

                return ScanResult::clean();
            }
        });

        try {
            $this->deliver($this->mime('Ana <ana@santoslaw.ph>', $this->address, 'Scan later', ['a.pdf' => '%PDF-1.4']));
        } catch (\Throwable) {
            // With the sync queue the job's failure surfaces here; a real worker retries it.
        }
        $email = MatterEmail::sole();
        $this->assertSame(MatterEmail::QUEUED, $email->status);
        $this->assertNotNull($email->raw_path);
        $this->assertSame(0, MatterFile::count());

        $down = false;
        app(InboundEmails::class)->file($email);
        $this->assertSame(MatterEmail::FILED, $email->refresh()->status);
        $this->assertSame(2, MatterFile::count());
    }

    public function test_the_webhook_needs_the_secret_and_an_eml_can_be_uploaded(): void
    {
        $this->deliver($this->mime('Ana <ana@santoslaw.ph>', $this->address), 'wrong')->assertStatus(401);
        // Never in the URL, where web server logs would keep it.
        $this->call('POST', '/api/webhooks/inbound-email?secret=s3cret-value', [], [], [], ['CONTENT_TYPE' => 'message/rfc822'], $this->mime('Ana <ana@santoslaw.ph>', $this->address))->assertStatus(401);
        $this->assertSame(0, MatterEmail::count());

        // SendGrid posts the raw message in the "email" field.
        $this->post('/api/webhooks/inbound-email', ['email' => $this->mime('Ana <ana@santoslaw.ph>', 'someone@else.ph', 'Bcc to matter', [], '<bcc@x>'), 'envelope' => json_encode(['to' => [$this->address]])], ['X-Inbound-Secret' => 's3cret-value'])
            ->assertOk()->assertJsonPath('filed', 1);

        $eml = UploadedFile::fake()->createWithContent('saved.eml', $this->mime('Clerk <clerk@court.gov.ph>', 'ana@santoslaw.ph', 'Order', [], '<order@court>'));
        $this->post("/api/v1/matters/{$this->matter->id}/emails", ['file' => $eml], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('status', 'filed');

        $emails = $this->getJson("/api/v1/matters/{$this->matter->id}/emails")->json('emails');
        $this->assertCount(2, $emails);
        $this->assertSame('Order', $emails[0]['subject']);

        // Other firms see nothing.
        $this->signIn(Role::ManagingPartner, Firm::factory()->create());
        $this->getJson("/api/v1/matter-emails/{$emails[0]['id']}")->assertNotFound();
    }
}
