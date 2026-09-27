<?php

namespace Tests\Feature\Billing;

use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\TimeEntry;
use App\Domain\Billing\Services\InvoiceGenerator;
use App\Domain\Matters\Models\Client;
use App\Domain\Matters\Models\Firm;
use App\Domain\Matters\Models\Matter;
use App\Domain\Trust\Models\TrustAccount;
use App\Domain\Trust\Services\TrustLedgerService;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class InvoicePaymentTest extends TestCase
{
    use RefreshDatabase;

    private Firm $firm;

    private Matter $matter;

    private User $partner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->firm = Firm::factory()->create(['vat_registered' => true]);
        $this->matter = Matter::factory()->for(Client::factory()->for($this->firm))->create();
        $this->partner = $this->signIn(Role::Partner, $this->firm);
    }

    /** 10,000.00 in fees + 1,200.00 VAT = 11,200.00. */
    private function issuedInvoice(): Invoice
    {
        TimeEntry::factory()->for($this->matter)->create(['user_id' => $this->partner->id, 'minutes' => 60, 'rate_cents' => 1_000_000]);
        $generator = app(InvoiceGenerator::class);

        return $generator->issue($generator->generateForMatter($this->matter, $this->partner));
    }

    private function pay(Invoice $invoice, array $data): TestResponse
    {
        return $this->postJson("/api/v1/invoices/{$invoice->id}/payments", $data + ['received_on' => today()->toDateString(), 'method' => 'bank_transfer']);
    }

    public function test_a_corporate_client_pays_net_of_withholding_in_installments(): void
    {
        $invoice = $this->issuedInvoice();

        // First installment: 5,000 received, 500 withheld (10% of 5,000 in fees).
        $this->pay($invoice, ['amount_cents' => 500_000, 'withholding_cents' => 50_000, 'reference' => 'BDO-1'])
            ->assertCreated()
            ->assertJsonPath('credited_cents', 550_000)
            ->assertJsonPath('form_2307_received_at', null);

        $this->getJson("/api/v1/invoices/{$invoice->id}")
            ->assertJsonPath('status', 'partially_paid')
            ->assertJsonPath('settled_cents', 550_000)
            ->assertJsonPath('balance_cents', 570_000)
            ->assertJsonPath('withholding_room_cents', 950_000)
            ->assertJsonCount(1, 'invoice_payments');

        // Final installment closes the invoice exactly.
        $this->pay($invoice, ['amount_cents' => 520_000, 'withholding_cents' => 50_000])->assertCreated();

        $this->getJson("/api/v1/invoices/{$invoice->id}")
            ->assertJsonPath('status', 'paid')
            ->assertJsonPath('balance_cents', 0)
            ->assertJsonPath('withholding_cents', 100_000);

        $this->pay($invoice, ['amount_cents' => 1])->assertStatus(422); // nothing left to pay
    }

    public function test_overpayment_and_withholding_beyond_the_fees_are_refused(): void
    {
        $invoice = $this->issuedInvoice();

        $this->pay($invoice, ['amount_cents' => 1_120_001])->assertStatus(422)->assertJsonValidationErrors('amount_cents');
        // Tax is withheld on fees, never on VAT: at most 10,000.00 here.
        $this->pay($invoice, ['amount_cents' => 0, 'withholding_cents' => 1_000_001])->assertStatus(422)->assertJsonValidationErrors('withholding_cents');
        $this->pay($invoice, ['amount_cents' => 0])->assertStatus(422);
        $this->pay($invoice, ['amount_cents' => 100, 'received_on' => today()->addDay()->toDateString()])->assertStatus(422);
        $this->pay($invoice, ['amount_cents' => 100, 'method' => 'trust'])->assertStatus(422);
    }

    public function test_draft_and_void_invoices_take_no_payments(): void
    {
        TimeEntry::factory()->for($this->matter)->create(['user_id' => $this->partner->id]);
        $draft = app(InvoiceGenerator::class)->generateForMatter($this->matter, $this->partner);

        $this->pay($draft, ['amount_cents' => 100])->assertStatus(422);
    }

    public function test_voiding_a_payment_reopens_the_invoice(): void
    {
        $invoice = $this->issuedInvoice();
        $id = $this->pay($invoice, ['amount_cents' => 1_120_000])->json('id');
        $this->assertSame('paid', $invoice->fresh()->status->value);

        // An invoice with live payments cannot be voided.
        $this->postJson("/api/v1/invoices/{$invoice->id}/void")->assertStatus(422);

        $this->postJson("/api/v1/invoice-payments/{$id}/void", [])->assertStatus(422); // reason required
        $this->postJson("/api/v1/invoice-payments/{$id}/void", ['reason' => 'Check bounced'])
            ->assertOk()
            ->assertJsonPath('void_reason', 'Check bounced');

        $fresh = $invoice->fresh();
        $this->assertSame('issued', $fresh->status->value);
        $this->assertSame(1_120_000, $fresh->balanceDue());
        $this->assertNull($fresh->paid_at);

        $this->postJson("/api/v1/invoice-payments/{$id}/void", ['reason' => 'again'])->assertStatus(422);
        $this->postJson("/api/v1/invoices/{$invoice->id}/void")->assertOk();
    }

    public function test_a_partial_trust_payment_is_disbursed_and_restored_on_void(): void
    {
        $invoice = $this->issuedInvoice();
        $account = TrustAccount::factory()->create(['client_id' => $this->matter->client_id]);
        app(TrustLedgerService::class)->deposit($account, 300_000, 'Deposit');

        $id = $this->pay($invoice, ['method' => 'other', 'amount_cents' => 300_000, 'trust_account_id' => $account->id])
            ->assertCreated()
            ->assertJsonPath('method', 'trust')
            ->assertJsonPath('is_trust', true)
            ->json('id');

        $this->assertSame(0, $account->fresh()->balance_cents);
        $this->assertSame('partially_paid', $invoice->fresh()->status->value);

        $this->postJson("/api/v1/invoice-payments/{$id}/void", ['reason' => 'Wrong account'])->assertOk();
        $this->assertSame(300_000, $account->fresh()->balance_cents);
    }

    public function test_the_2307_tracker_lists_withholding_until_the_certificate_arrives(): void
    {
        Storage::fake('local');
        $invoice = $this->issuedInvoice();
        $id = $this->pay($invoice, ['amount_cents' => 1_020_000, 'withholding_cents' => 100_000])->json('id');
        $this->pay($invoice, [])->assertStatus(422);

        $this->getJson('/api/v1/invoice-payments/awaiting-2307')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.withholding_cents', 100_000)
            ->assertJsonPath('0.invoice.number', $invoice->number);

        $this->getJson('/api/v1/analytics/dashboard')->assertJsonPath('metrics.withholding_awaiting_2307_cents', 100_000);

        $this->post("/api/v1/invoice-payments/{$id}/form-2307", [
            'form_2307' => UploadedFile::fake()->create('2307.pdf', 40, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('form_2307_file_id', fn ($v) => $v !== null);

        $this->assertDatabaseHas('matter_files', ['matter_id' => $this->matter->id, 'description' => "BIR Form 2307 for invoice {$invoice->number}"]);
        $this->getJson('/api/v1/invoice-payments/awaiting-2307')->assertJsonCount(0);
    }

    public function test_payments_without_withholding_need_no_2307(): void
    {
        $invoice = $this->issuedInvoice();
        $id = $this->pay($invoice, ['amount_cents' => 100_000])->json('id');

        $this->postJson("/api/v1/invoice-payments/{$id}/form-2307")->assertStatus(422);
    }

    public function test_only_finance_roles_record_payments_and_other_firms_cannot_see_them(): void
    {
        $invoice = $this->issuedInvoice();
        $id = $this->pay($invoice, ['amount_cents' => 100_000])->json('id');

        $this->signIn(Role::Associate, $this->firm);
        $this->pay($invoice, ['amount_cents' => 100_000])->assertForbidden();
        $this->getJson("/api/v1/invoices/{$invoice->id}/payments")->assertOk()->assertJsonCount(1);

        $this->signIn(Role::Partner, Firm::factory()->create());
        $this->getJson("/api/v1/invoices/{$invoice->id}/payments")->assertNotFound();
        $this->postJson("/api/v1/invoice-payments/{$id}/void", ['reason' => 'x'])->assertNotFound();
    }

    public function test_every_payment_and_void_is_audited(): void
    {
        $invoice = $this->issuedInvoice();
        $id = $this->pay($invoice, ['amount_cents' => 100_000])->json('id');
        $this->postJson("/api/v1/invoice-payments/{$id}/void", ['reason' => 'Duplicate']);

        $this->assertDatabaseHas('audit_logs', ['subject_type' => 'invoice_payment', 'subject_id' => $id, 'action' => 'created']);
        $this->assertDatabaseHas('audit_logs', ['subject_type' => 'invoice_payment', 'subject_id' => $id, 'action' => 'updated']);
    }
}
