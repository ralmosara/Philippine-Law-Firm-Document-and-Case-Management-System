<?php

namespace Tests\Feature\Billing;

use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\Models\TimeEntry;
use App\Domain\Billing\Services\InvoiceGenerator;
use App\Domain\Matters\Models\Client;
use App\Domain\Matters\Models\Firm;
use App\Domain\Matters\Models\Matter;
use App\Enums\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OnlineRefundTest extends TestCase
{
    use RefreshDatabase;

    private Firm $firm;

    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.paymongo.secret_key' => 'sk_test_123']);
        $this->firm = Firm::factory()->create(['vat_registered' => false]);
        $matter = Matter::factory()->for(Client::factory()->for($this->firm))->create();
        $partner = $this->signIn(Role::ManagingPartner, $this->firm);
        TimeEntry::factory()->for($matter)->create(['user_id' => $partner->id, 'minutes' => 60, 'rate_cents' => 500_000]);
        $generator = app(InvoiceGenerator::class);
        $this->invoice = $generator->issue($generator->generateForMatter($matter, $partner));
    }

    private function payment(string $status, ?string $paymentId = 'pay_abc'): Payment
    {
        $payment = Payment::create(['firm_id' => $this->firm->id, 'invoice_id' => $this->invoice->id, 'provider' => 'paymongo', 'checkout_id' => 'cs_'.uniqid(), 'checkout_url' => 'https://checkout.paymongo.com/x', 'amount_cents' => 500_000]);
        $payment->forceFill(['status' => $status, 'provider_payment_id' => $paymentId, 'paid_at' => now()])->save();

        return $payment;
    }

    public function test_an_unapplied_payment_is_refunded_through_paymongo(): void
    {
        Http::fake(['api.paymongo.com/v1/refunds' => Http::response(['data' => ['id' => 'ref_123', 'attributes' => ['status' => 'pending']]])]);
        $payment = $this->payment(Payment::UNAPPLIED);

        $this->postJson("/api/v1/online-payments/{$payment->id}/refund", ['reason' => 'duplicate', 'notes' => 'Paid twice by the client'])
            ->assertOk()
            ->assertJsonPath('payments.0.refund.id', 'ref_123')
            ->assertJsonPath('payments.0.refund.status', 'pending');

        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://api.paymongo.com/v1/refunds'
            && $r['data']['attributes'] === ['amount' => 500_000, 'payment_id' => 'pay_abc', 'reason' => 'duplicate', 'notes' => 'Paid twice by the client']);

        // Not twice.
        $this->postJson("/api/v1/online-payments/{$payment->id}/refund", ['reason' => 'duplicate'])->assertStatus(422)->assertJsonPath('errors.payment.0', 'This payment has already been refunded.');
        Http::assertSentCount(1);
    }

    public function test_only_unapplied_payments_and_paymongo_errors_are_explained(): void
    {
        Http::fake(['api.paymongo.com/v1/refunds' => Http::response(['errors' => [['detail' => 'The payment is not refundable.']]], 400)]);

        $applied = $this->payment(Payment::PAID);
        $this->postJson("/api/v1/online-payments/{$applied->id}/refund", ['reason' => 'others'])->assertStatus(422)->assertJsonValidationErrors('payment');

        $noId = $this->payment(Payment::UNAPPLIED, null);
        $this->postJson("/api/v1/online-payments/{$noId->id}/refund", ['reason' => 'others'])->assertStatus(422)->assertJsonPath('errors.payment.0', 'PayMongo did not report a payment ID; refund it in the PayMongo dashboard.');

        $unapplied = $this->payment(Payment::UNAPPLIED);
        $this->postJson("/api/v1/online-payments/{$unapplied->id}/refund", ['reason' => 'others'])->assertStatus(422)->assertJsonPath('errors.payment.0', 'PayMongo: The payment is not refundable.');
        $this->assertNull($unapplied->fresh()->refund_id);

        $this->postJson("/api/v1/online-payments/{$unapplied->id}/refund", ['reason' => 'because'])->assertStatus(422)->assertJsonValidationErrors('reason');
    }

    public function test_only_finance_staff_refund(): void
    {
        $payment = $this->payment(Payment::UNAPPLIED);
        $this->signIn(Role::Associate, $this->firm);
        $this->postJson("/api/v1/online-payments/{$payment->id}/refund", ['reason' => 'duplicate'])->assertForbidden();
    }
}
