<?php

namespace Tests\Feature\Tax;

use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\TimeEntry;
use App\Domain\Billing\Services\InvoiceGenerator;
use App\Domain\Billing\Services\InvoicePayments;
use App\Domain\Matters\Models\Client;
use App\Domain\Matters\Models\Firm;
use App\Domain\Matters\Models\Matter;
use App\Domain\Tax\BirCalendar;
use App\Domain\Tax\Models\TaxFiling;
use App\Domain\Tax\Notifications\TaxFilingDue;
use App\Domain\Tax\TaxFilingReminders;
use App\Enums\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class TaxComplianceTest extends TestCase
{
    use RefreshDatabase;

    private Firm $firm;

    private User $partner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-08-10 09:00', 'Asia/Manila'));
        $this->firm = Firm::factory()->create(['name' => 'Santos & Reyes Law', 'tin' => '123-456-789-000', 'vat_registered' => true, 'taxpayer_type' => 'juridical', 'withholding_atc' => 'WC010']);
        $this->partner = $this->signIn(Role::ManagingPartner, $this->firm);
    }

    private function invoiceFor(Client $client, int $feesCents): Invoice
    {
        $matter = Matter::factory()->for($client)->create();
        TimeEntry::factory()->for($matter)->create(['user_id' => $this->partner->id, 'minutes' => 60, 'rate_cents' => $feesCents]);
        $generator = app(InvoiceGenerator::class);

        return $generator->issue($generator->generateForMatter($matter, $this->partner));
    }

    public function test_the_calendar_lists_the_firms_returns_with_their_deadlines(): void
    {
        $items = collect(app(BirCalendar::class)->forYear($this->firm, 2026))->keyBy(fn ($i) => "{$i['form']} {$i['period']}");

        // VAT 25 days after the quarter; Q3 ends Sep 30 -> Oct 25, a Sunday -> Monday Oct 26.
        $this->assertSame('2026-10-26', $items['2550Q 2026-Q3']['due_on']);
        // Partnership/corporation quarterly ITR 60 days after the quarter: May 30 2026 is a Saturday -> June 1.
        $this->assertSame('2026-06-01', $items['1702Q 2026-Q1']['due_on']);
        $this->assertArrayNotHasKey('1702Q 2026-Q4', $items->all()); // the annual return covers Q4
        $this->assertSame('2027-04-15', $items['1702 2026']['due_on']);
        $this->assertSame('2026-11-02', $items['1601EQ 2026-Q3']['due_on']); // Oct 31 (Sat), Nov 1 (Sun) -> Nov 2
        $this->assertArrayHasKey('0619E 2026-01', $items->all());
        $this->assertArrayNotHasKey('0619E 2026-03', $items->all()); // the quarter-end month goes on the 1601-EQ
        $this->assertSame('2027-01-15', $items['1601C 2026-12']['due_on']);

        // A non-VAT individual practitioner without staff files different returns.
        $solo = Firm::factory()->create(['vat_registered' => false, 'taxpayer_type' => 'individual', 'has_employees' => false]);
        $forms = collect(app(BirCalendar::class)->forYear($solo, 2026))->pluck('form')->unique()->values()->all();
        $this->assertContains('2551Q', $forms);
        $this->assertContains('1701Q', $forms);
        $this->assertNotContains('2550Q', $forms);
        $this->assertNotContains('1601C', $forms);
        $this->assertSame('2026-05-15', collect(app(BirCalendar::class)->forYear($solo, 2026))->where('form', '1701Q')->firstWhere('period', '2026-Q1')['due_on']);
    }

    public function test_filings_are_marked_filed_with_the_reference(): void
    {
        $filing = collect($this->getJson('/api/v1/tax/filings?year=2026')->assertOk()->json('filings'))->firstWhere('form', '2550Q');

        $this->patchJson("/api/v1/tax/filings/{$filing['id']}", ['status' => 'filed'])->assertStatus(422)->assertJsonValidationErrors('filed_on');
        $this->patchJson("/api/v1/tax/filings/{$filing['id']}", ['status' => 'filed', 'filed_on' => '2026-04-24', 'reference' => 'eBIR-12345'])
            ->assertOk()
            ->assertJsonPath('status', 'filed')
            ->assertJsonPath('reference', 'eBIR-12345')
            ->assertJsonPath('filed_by', $this->partner->name);

        // Visiting again does not duplicate or reset the calendar.
        $this->getJson('/api/v1/tax/filings?year=2026')->assertOk();
        $this->assertSame(1, TaxFiling::where('form', '2550Q')->where('period', $filing['period'])->count());
        $this->assertSame('filed', TaxFiling::find($filing['id'])->status);
    }

    public function test_the_quarter_shows_invoiced_sales_collections_and_the_sawt(): void
    {
        $acme = Client::factory()->for($this->firm)->create(['name' => 'Acme Trading Corp.', 'tin' => '222-333-444-000']);
        $invoice = $this->invoiceFor($acme, 1_000_000); // 10,000 fees + 1,200 VAT
        app(InvoicePayments::class)->record($invoice, ['method' => 'bank_transfer', 'amount_cents' => 1_020_000, 'withholding_cents' => 100_000, 'received_on' => '2026-08-05'], $this->partner);

        $bayan = Client::factory()->for($this->firm)->create(['name' => 'Bayanihan Holdings', 'tin' => '555-666-777-000']);
        $other = $this->invoiceFor($bayan, 500_000);
        $payment = app(InvoicePayments::class)->record($other, ['method' => 'check', 'amount_cents' => 510_000, 'withholding_cents' => 50_000, 'received_on' => '2026-08-06'], $this->partner);
        app(InvoicePayments::class)->mark2307Received($payment, null);

        $response = $this->getJson('/api/v1/tax/quarter?year=2026&quarter=3')->assertOk()
            ->assertJsonPath('invoiced.count', 2)
            ->assertJsonPath('invoiced.fees_cents', 1_500_000)
            ->assertJsonPath('invoiced.vat_cents', 180_000)
            ->assertJsonPath('collected.withheld_cents', 150_000)
            ->assertJsonPath('creditable.with_2307_cents', 50_000)
            ->assertJsonPath('creditable.without_2307_count', 1);

        // Rows with a 2307 first; income payment is the fee part of what was settled.
        $sawt = $response->json('sawt');
        $this->assertSame('Bayanihan Holdings', $sawt[0]['payor_name']);
        $this->assertTrue($sawt[0]['with_2307']);
        $this->assertSame(500_000, $sawt[0]['income_payment_cents']);
        $this->assertEquals(10, $sawt[0]['rate']);
        $this->assertSame('WC010', $sawt[0]['atc']);
        $this->assertFalse($sawt[1]['with_2307']);

        $csv = $this->get('/api/v1/tax/sawt.csv?year=2026&quarter=3')->assertOk()->streamedContent();
        $this->assertStringContainsString('555-666-777-000,"Bayanihan Holdings",WC010,"Professional fees",5000.00,10,500.00,Yes', $csv);
        $this->assertStringContainsString('NO: do not claim until received', $csv);
    }

    public function test_finance_partners_are_reminded_before_returns_fall_due(): void
    {
        Notification::fake();
        $associate = User::factory()->role(Role::Associate)->create(['firm_id' => $this->firm->id]);
        $reminders = app(TaxFilingReminders::class);

        // Aug 10: 0619-E for July is due today, and 1601-C for July too.
        $this->assertGreaterThan(0, $reminders->run(CarbonImmutable::parse('2026-08-10')));
        $this->assertSame(0, $reminders->run(CarbonImmutable::parse('2026-08-10'))); // once per stage
        Notification::assertSentTo($this->partner, TaxFilingDue::class, fn ($n) => $n->filing->form === '0619E' && $n->filing->period === '2026-07');
        Notification::assertNotSentTo($associate, TaxFilingDue::class);

        // Filed returns are not chased.
        TaxFiling::where('form', '0619E')->where('period', '2026-07')->update(['status' => 'filed', 'filed_on' => '2026-08-09']);
        Notification::fake();
        $reminders->run(CarbonImmutable::parse('2026-08-12'));
        Notification::assertNotSentTo($this->partner, TaxFilingDue::class, fn ($n) => $n->filing->form === '0619E' && $n->filing->period === '2026-07');
    }

    public function test_only_finance_roles_and_only_their_firm(): void
    {
        $this->signIn(Role::Associate, $this->firm);
        $this->getJson('/api/v1/tax/quarter')->assertForbidden();

        $this->signIn(Role::ManagingPartner, $this->firm);
        $id = $this->getJson('/api/v1/tax/filings')->json('filings.0.id');
        $this->signIn(Role::ManagingPartner, Firm::factory()->create());
        $this->patchJson("/api/v1/tax/filings/{$id}", ['status' => 'not_applicable'])->assertNotFound();
    }
}
