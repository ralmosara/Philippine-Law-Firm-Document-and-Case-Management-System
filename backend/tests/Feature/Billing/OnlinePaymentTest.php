<?php

namespace Tests\Feature\Billing;

use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\Models\TimeEntry;
use App\Domain\Billing\Services\InvoiceGenerator;
use App\Domain\Billing\Services\InvoicePayments;
use App\Domain\Matters\Models\Client;
use App\Domain\Matters\Models\Firm;
use App\Domain\Matters\Models\Matter;
use App\Enums\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OnlinePaymentTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK_SECRET = 'whsk_test_secret';

    private Client $client;

    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.paymongo.secret_key' => 'sk_test_123',
            'services.paymongo.webhook_secret' => self::WEBHOOK_SECRET,
            'app.frontend_url' => 'https://app.lex.ph',
        ]);

        $firm = Firm::factory()->create(['vat_registered' => false]);
        $this->client = Client::factory()->for($firm)->withPortal('secret-pass')->create();
        $matter = Matter::factory()->for($this->client)->create();
        $partner = $this->signIn(Role::Partner, $firm);
        TimeEntry::factory()->for($matter)->create(['user_id' => $partner->id, 'minutes' => 60, 'rate_cents' => 500_000]);

        $generator = app(InvoiceGenerator::class);
        $this->invoice = $generator->issue($generator->generateForMatter($matter, $partner));

        Http::fake([
            'api.paymongo.com/v1/checkout_sessions' => Http::response([
                'data' => ['id' => 'cs_test_abc', 'attributes' => ['checkout_url' => 'https://checkout.paymongo.com/cs_test_abc']],
            ]),
        ]);
    }

    public function test_a_client_is_sent_to_a_hosted_checkout_for_the_invoice_total(): void
    {
        $this->actingAs($this->client, 'client');

        $this->getJson('/api/portal/invoices')->assertJsonPath('data.0.can_pay_online', true);

        $this->postJson("/api/portal/invoices/{$this->invoice->id}/checkout")
            ->assertCreated()
            ->assertJsonPath('checkout_url', 'https://checkout.paymongo.com/cs_test_abc');

        Http::assertSent(fn (HttpRequest $request) => $request['data']['attributes']['line_items'][0]['amount'] === 500_000
            && $request['data']['attributes']['reference_number'] === $this->invoice->number
            && str_starts_with($request['data']['attributes']['success_url'], 'https://app.lex.ph/portal?payment=success')
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('sk_test_123:')));

        $this->assertDatabaseHas('payments', ['invoice_id' => $this->invoice->id, 'status' => 'pending', 'amount_cents' => 500_000]);
        // Returning from checkout proves nothing; only the webhook settles.
        $this->assertSame(InvoiceStatus::Issued, $this->invoice->fresh()->status);
    }

    public function test_the_signed_webhook_marks_the_invoice_paid_once(): void
    {
        $this->startCheckout();
        $event = $this->paidEvent(500_000);

        $this->postWebhook($event, 'bad-signature')->assertStatus(400);
        $this->assertSame(InvoiceStatus::Issued, $this->invoice->fresh()->status);

        $this->postWebhook($event)->assertOk();
        $this->postWebhook($event)->assertOk(); // PayMongo retries: no double effect

        $invoice = $this->invoice->fresh();
        $this->assertSame(InvoiceStatus::Paid, $invoice->status);
        $this->assertSame('PAYMONGO-pay_test_1', $invoice->payment_reference);
        $this->assertDatabaseHas('payments', ['checkout_id' => 'cs_test_abc', 'status' => 'paid', 'method' => 'gcash', 'provider_payment_id' => 'pay_test_1']);
    }

    public function test_money_that_cannot_be_applied_is_flagged_not_lost(): void
    {
        $this->startCheckout();
        app(InvoiceGenerator::class)->void($this->invoice->fresh());

        $this->postWebhook($this->paidEvent(500_000))->assertOk();

        $this->assertDatabaseHas('payments', ['checkout_id' => 'cs_test_abc', 'status' => Payment::UNAPPLIED]);
        $this->assertSame(InvoiceStatus::Void, $this->invoice->fresh()->status);
    }

    public function test_an_amount_mismatch_is_not_applied(): void
    {
        $this->startCheckout();

        $this->postWebhook($this->paidEvent(100))->assertOk();

        $this->assertSame(InvoiceStatus::Issued, $this->invoice->fresh()->status);
        $this->assertDatabaseHas('payments', ['checkout_id' => 'cs_test_abc', 'status' => Payment::UNAPPLIED]);
    }

    public function test_after_a_partial_payment_the_checkout_charges_only_the_balance(): void
    {
        app(InvoicePayments::class)->record($this->invoice, ['method' => 'check', 'amount_cents' => 200_000], null);

        $this->actingAs($this->client, 'client');
        $this->getJson('/api/portal/invoices')
            ->assertJsonPath('data.0.status', 'partially_paid')
            ->assertJsonPath('data.0.balance_cents', 300_000)
            ->assertJsonPath('outstanding_cents', 300_000);

        $this->postJson("/api/portal/invoices/{$this->invoice->id}/checkout")->assertCreated();
        Http::assertSent(fn (HttpRequest $request) => $request['data']['attributes']['line_items'][0]['amount'] === 300_000);

        $this->postWebhook($this->paidEvent(300_000))->assertOk();

        $this->assertSame(InvoiceStatus::Paid, $this->invoice->fresh()->status);
        $this->assertDatabaseHas('invoice_payments', ['invoice_id' => $this->invoice->id, 'method' => 'online', 'amount_cents' => 300_000, 'reference' => 'PAYMONGO-pay_test_1']);
    }

    public function test_the_firm_can_create_a_payment_link(): void
    {
        $this->postJson("/api/v1/invoices/{$this->invoice->id}/payment-link")
            ->assertCreated()->assertJsonPath('checkout_url', 'https://checkout.paymongo.com/cs_test_abc');

        $this->getJson("/api/v1/invoices/{$this->invoice->id}")
            ->assertJsonPath('can_pay_online', true)
            ->assertJsonPath('payments.0.status', 'pending');
    }

    public function test_online_payment_is_off_without_a_key_and_for_unpayable_invoices(): void
    {
        $other = Client::factory()->for(Firm::find($this->client->firm_id))->withPortal('x')->create();
        $this->actingAs($other, 'client');
        $this->postJson("/api/portal/invoices/{$this->invoice->id}/checkout")->assertNotFound();

        $this->actingAs($this->client, 'client');
        config(['services.paymongo.secret_key' => null]);
        $this->getJson('/api/portal/invoices')->assertJsonPath('data.0.can_pay_online', false);
        $this->postJson("/api/portal/invoices/{$this->invoice->id}/checkout")->assertStatus(503);

        Http::assertNothingSent();
    }

    private function startCheckout(): void
    {
        $this->actingAs($this->client, 'client');
        $this->postJson("/api/portal/invoices/{$this->invoice->id}/checkout")->assertCreated();
    }

    private function paidEvent(int $amount): array
    {
        return ['data' => ['id' => 'evt_1', 'attributes' => [
            'type' => 'checkout_session.payment.paid',
            'livemode' => false,
            'data' => ['id' => 'cs_test_abc', 'attributes' => [
                'payment_method_used' => 'gcash',
                'payments' => [['id' => 'pay_test_1', 'attributes' => ['amount' => $amount, 'status' => 'paid']]],
            ]],
        ]]];
    }

    private function postWebhook(array $event, ?string $signature = null)
    {
        $body = json_encode($event);
        $timestamp = (string) time();
        $signature ??= hash_hmac('sha256', "{$timestamp}.{$body}", self::WEBHOOK_SECRET);

        return $this->call('POST', '/api/webhooks/paymongo', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_PAYMONGO_SIGNATURE' => "t={$timestamp},te={$signature},li=",
        ], $body);
    }
}
