<?php

namespace Tests\Feature\Billing;

use App\Domain\Analytics\Reports;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\TimeEntry;
use App\Domain\Billing\Services\InvoiceGenerator;
use App\Domain\Billing\Services\InvoicePayments;
use App\Domain\Billing\Statements\StatementOfAccount;
use App\Domain\Matters\Models\Client;
use App\Domain\Matters\Models\Firm;
use App\Domain\Matters\Models\Matter;
use App\Enums\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceAdjustmentsTest extends TestCase
{
    use RefreshDatabase;

    private Firm $firm;

    private Client $client;

    private User $partner;

    private Matter $matter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-01 09:00', 'Asia/Manila'));
        $this->firm = Firm::factory()->create(['vat_registered' => true]);
        $this->client = Client::factory()->for($this->firm)->create();
        $this->partner = $this->signIn(Role::ManagingPartner, $this->firm);
        $this->matter = Matter::factory()->for($this->client)->create(['responsible_lawyer_id' => $this->partner->id]);
    }

    /** Two hours at ₱5,000 (₱10,000 of fees), one at ₱5,000. */
    private function draft(): Invoice
    {
        TimeEntry::factory()->for($this->matter)->create(['user_id' => $this->partner->id, 'minutes' => 120, 'rate_cents' => 500_000, 'work_date' => '2026-09-20']);
        TimeEntry::factory()->for($this->matter)->create(['user_id' => $this->partner->id, 'minutes' => 60, 'rate_cents' => 500_000, 'work_date' => '2026-09-21']);

        return app(InvoiceGenerator::class)->generateForMatter($this->matter, $this->partner);
    }

    public function test_a_draft_line_is_written_down_and_vat_follows(): void
    {
        $invoice = $this->draft();
        $line = $invoice->lines->firstWhere('amount_cents', 1_000_000);

        $this->putJson("/api/v1/invoices/{$invoice->id}/lines/{$line->id}", ['amount_cents' => 600_000])->assertJsonValidationErrors('reason');
        $this->putJson("/api/v1/invoices/{$invoice->id}/lines/{$line->id}", ['amount_cents' => 2_000_000, 'reason' => 'x'])->assertJsonValidationErrors('amount_cents');

        $this->putJson("/api/v1/invoices/{$invoice->id}/lines/{$line->id}", ['amount_cents' => 600_000, 'reason' => 'Research not chargeable'])
            ->assertOk()
            ->assertJsonPath('subtotal_cents', 1_100_000)
            ->assertJsonPath('vat_cents', 132_000)
            ->assertJsonPath('total_cents', 1_232_000);
        $this->assertSame(1_000_000, $line->fresh()->original_amount_cents);

        // Back to the recorded amount clears the write-down.
        $this->putJson("/api/v1/invoices/{$invoice->id}/lines/{$line->id}", ['amount_cents' => 1_000_000])->assertOk()->assertJsonPath('subtotal_cents', 1_500_000);
        $this->assertNull($line->fresh()->original_amount_cents);
    }

    public function test_a_discount_comes_off_fees_before_vat_and_shows_on_the_bill(): void
    {
        $invoice = $this->draft();

        $this->putJson("/api/v1/invoices/{$invoice->id}/discount", ['discount_cents' => 500_000])->assertJsonValidationErrors('discount_reason');
        $this->putJson("/api/v1/invoices/{$invoice->id}/discount", ['discount_cents' => 300_000, 'discount_reason' => 'Professional courtesy'])
            ->assertOk()->assertJsonPath('subtotal_cents', 1_200_000)->assertJsonPath('vat_cents', 144_000)->assertJsonPath('total_cents', 1_344_000);

        $this->get("/api/v1/invoices/{$invoice->id}/pdf")->assertOk();

        app(InvoiceGenerator::class)->issue($invoice->fresh());
        $this->putJson("/api/v1/invoices/{$invoice->id}/discount", ['discount_cents' => 0])->assertJsonValidationErrors('status');
    }

    public function test_writing_off_a_balance_takes_it_out_of_receivables_and_can_be_undone(): void
    {
        $invoice = app(InvoiceGenerator::class)->issue($this->draft());
        app(InvoicePayments::class)->record($invoice, ['method' => 'cash', 'amount_cents' => 680_000], $this->partner);

        $this->postJson("/api/v1/invoices/{$invoice->id}/write-off", [])->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/invoices/{$invoice->id}/write-off", ['reason' => 'Client insolvent'])
            ->assertOk()
            ->assertJsonPath('status', 'written_off')
            ->assertJsonPath('written_off_cents', 1_000_000)
            ->assertJsonPath('balance_cents', 0)
            ->assertJsonPath('written_off_by', $this->partner->name);

        $this->assertSame(0, app(StatementOfAccount::class)->build($this->client)['total_due']);
        $this->assertSame([], app(Reports::class)->agedReceivables(CarbonImmutable::today())['rows']);
        $this->postJson("/api/v1/invoices/{$invoice->id}/payments", ['method' => 'cash', 'amount_cents' => 100])->assertStatus(422);
        $payment = $invoice->invoicePayments()->first();
        $this->postJson("/api/v1/invoice-payments/{$payment->id}/void", ['reason' => 'x'])->assertStatus(422);

        $this->deleteJson("/api/v1/invoices/{$invoice->id}/write-off")->assertOk()->assertJsonPath('status', 'partially_paid')->assertJsonPath('balance_cents', 1_000_000);
    }

    public function test_only_finance_can_adjust(): void
    {
        $invoice = $this->draft();
        $this->signIn(Role::Associate, $this->firm);

        $this->putJson("/api/v1/invoices/{$invoice->id}/discount", ['discount_cents' => 100, 'discount_reason' => 'x'])->assertForbidden();
    }

    public function test_the_report_shows_what_was_not_charged_by_lawyer(): void
    {
        $invoice = $this->draft();
        $line = $invoice->lines->firstWhere('amount_cents', 1_000_000);
        $this->putJson("/api/v1/invoices/{$invoice->id}/lines/{$line->id}", ['amount_cents' => 800_000, 'reason' => 'Cap'])->assertOk();
        $this->putJson("/api/v1/invoices/{$invoice->id}/discount", ['discount_cents' => 100_000, 'discount_reason' => 'Courtesy'])->assertOk();
        app(InvoiceGenerator::class)->issue($invoice->fresh());
        $this->postJson("/api/v1/invoices/{$invoice->id}/write-off", ['reason' => 'Uncollectible'])->assertOk();

        $report = $this->getJson('/api/v1/reports/write-offs?from=2026-01-01&to=2026-12-31')->assertOk()->json();
        $this->assertSame(['lawyer' => $this->partner->name, 'invoices' => 1, 'written_down' => 200_000, 'discounted' => 100_000, 'written_off' => 1_344_000, 'total' => 1_644_000], $report['rows'][0]);
        $this->get('/api/v1/reports/write-offs?format=csv')->assertOk();
    }
}
