<?php

namespace Tests\Feature\Billing;

use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\PaymentProof;
use App\Domain\Billing\Models\TimeEntry;
use App\Domain\Billing\Notifications\PaymentProofReviewed;
use App\Domain\Billing\Notifications\PaymentProofSubmitted;
use App\Domain\Billing\Services\InvoiceGenerator;
use App\Domain\Documents\Models\MatterFile;
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

class PaymentProofTest extends TestCase
{
    use RefreshDatabase;

    private Firm $firm;

    private Client $client;

    private User $partner;

    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Storage::fake('local');
        $this->travelTo(CarbonImmutable::parse('2026-10-06 09:00', 'Asia/Manila'));
        $this->firm = Firm::factory()->create();
        $this->client = Client::factory()->for($this->firm)->withPortal('secret-pass')->create(['email' => 'juan@example.ph', 'locale' => 'fil']);
        $this->partner = $this->signIn(Role::ManagingPartner, $this->firm);
        $matter = Matter::factory()->for($this->client)->create();
        TimeEntry::factory()->for($matter)->create(['user_id' => $this->partner->id, 'minutes' => 120, 'rate_cents' => 500_000]);
        $generator = app(InvoiceGenerator::class);
        $this->invoice = $generator->issue($generator->generateForMatter($matter, $this->partner));   // ₱10,000
    }

    private function upload(array $data = [], ?int $invoice = null)
    {
        $this->actingAs($this->client, 'client');

        return $this->post('/api/portal/invoices/'.($invoice ?? $this->invoice->id).'/payment-proofs', [
            'file' => UploadedFile::fake()->create('gcash.pdf', 40, 'application/pdf'),
            'amount_cents' => 600_000, 'paid_on' => '2026-10-05', 'method' => 'e_wallet', 'reference' => 'GC 1234 5678',
            ...$data,
        ], ['Accept' => 'application/json']);
    }

    private function asPartner(): void
    {
        $this->actingAs($this->partner, 'web')->actingAs($this->partner, 'sanctum');
    }

    public function test_a_client_uploads_proof_and_finance_confirms_it(): void
    {
        $this->upload()->assertCreated()->assertJsonPath('status', 'pending');
        $proof = PaymentProof::sole();
        $this->assertSame("Proof of payment for {$this->invoice->number}", MatterFile::findOrFail($proof->matter_file_id)->description);
        Notification::assertSentTo($this->partner, PaymentProofSubmitted::class);

        $this->getJson('/api/portal/invoices')->assertOk()->assertJsonPath('data.0.payment_proofs.0.status', 'pending');

        $this->asPartner();
        $this->getJson('/api/v1/payment-proofs')->assertOk()->assertJsonPath('data.0.reference', 'GC 1234 5678')->assertJsonPath('data.0.file.name', 'gcash.pdf');
        $this->postJson("/api/v1/payment-proofs/{$proof->id}/confirm")->assertOk()->assertJsonPath('status', 'confirmed');

        $invoice = $this->invoice->fresh();
        $this->assertSame('partially_paid', $invoice->status->value);
        $payment = $invoice->invoicePayments()->sole();
        $this->assertSame([600_000, 'e_wallet', 'GC 1234 5678', '2026-10-05'], [$payment->amount_cents, $payment->method, $payment->reference, $payment->received_on->toDateString()]);
        Notification::assertSentTo($this->client, PaymentProofReviewed::class, function (PaymentProofReviewed $n) {
            app()->setLocale('fil');
            $subject = $n->toMail($this->client)->subject;
            app()->setLocale('en');

            return str_starts_with($subject, 'Natanggap ang bayad');
        });
        $this->postJson("/api/v1/payment-proofs/{$proof->id}/confirm")->assertJsonValidationErrors('proof');
    }

    public function test_a_rejection_needs_a_reason_the_client_sees(): void
    {
        $this->upload()->assertCreated();
        $proof = PaymentProof::sole();
        $this->asPartner();

        $this->postJson("/api/v1/payment-proofs/{$proof->id}/reject", [])->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/payment-proofs/{$proof->id}/reject", ['reason' => 'No transfer with that reference reached our account.'])->assertOk();
        $this->assertSame(0, $this->invoice->fresh()->invoicePayments()->count());

        $this->actingAs($this->client, 'client');
        $this->getJson('/api/portal/invoices')->assertJsonPath('data.0.payment_proofs.0.reject_reason', 'No transfer with that reference reached our account.');
    }

    public function test_more_than_the_balance_or_another_clients_bill_is_refused(): void
    {
        $this->upload(['amount_cents' => 2_000_000])->assertJsonValidationErrors('amount_cents');

        $other = Client::factory()->for($this->firm)->create();
        $matter = Matter::factory()->for($other)->create();
        TimeEntry::factory()->for($matter)->create(['user_id' => $this->partner->id, 'minutes' => 60, 'rate_cents' => 100_000]);
        $theirs = app(InvoiceGenerator::class)->issue(app(InvoiceGenerator::class)->generateForMatter($matter, $this->partner));
        $this->upload([], $theirs->id)->assertNotFound();
    }

    public function test_only_finance_reviews(): void
    {
        $this->upload()->assertCreated();
        $this->signIn(Role::Associate, $this->firm);
        $this->getJson('/api/v1/payment-proofs')->assertForbidden();
    }
}
