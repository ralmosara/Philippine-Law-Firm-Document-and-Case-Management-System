<?php

namespace Tests\Feature\Reports;

use App\Domain\Billing\Models\Expense;
use App\Domain\Billing\Models\TimeEntry;
use App\Domain\Billing\Services\InvoiceAdjustments;
use App\Domain\Billing\Services\InvoiceGenerator;
use App\Domain\Billing\Services\InvoicePayments;
use App\Domain\Matters\Models\Client;
use App\Domain\Matters\Models\Firm;
use App\Domain\Matters\Models\Matter;
use App\Domain\Trust\Models\TrustAccount;
use App\Domain\Trust\Services\TrustLedgerService;
use App\Enums\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BooksOfAccountsTest extends TestCase
{
    use RefreshDatabase;

    private User $partner;

    private Matter $matter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-09-10 10:00', 'Asia/Manila'));
        $firm = Firm::factory()->create(['vat_registered' => true]);
        $this->partner = $this->signIn(Role::ManagingPartner, $firm);
        $client = Client::factory()->for($firm)->create(['name' => 'Ana Cruz', 'tin' => '123-456-789-000']);
        $this->matter = Matter::factory()->for($client)->create();

        // September: a ₱10,000 bill (₱1,200 VAT), ₱5,600 paid with ₱500 withheld, a ₱1,000 filing fee, a trust deposit.
        TimeEntry::factory()->for($this->matter)->create(['user_id' => $this->partner->id, 'minutes' => 120, 'rate_cents' => 500_000]);
        $generator = app(InvoiceGenerator::class);
        $invoice = $generator->issue($generator->generateForMatter($this->matter, $this->partner));
        app(InvoicePayments::class)->record($invoice, ['method' => 'bank_transfer', 'amount_cents' => 560_000, 'withholding_cents' => 50_000, 'reference' => 'BDO-777'], $this->partner);
        Expense::create(['firm_id' => $firm->id, 'matter_id' => $this->matter->id, 'user_id' => $this->partner->id, 'expense_date' => '2026-09-12', 'category' => 'filing_fee', 'description' => 'Docket fees', 'amount_cents' => 100_000]);
        app(TrustLedgerService::class)->deposit(TrustAccount::factory()->for($firm)->create(['client_id' => $client->id]), 2_000_000, 'Advance for costs', by: $this->partner);

        // October: the rest is written off.
        $this->travelTo(CarbonImmutable::parse('2026-10-03 10:00', 'Asia/Manila'));
        app(InvoiceAdjustments::class)->writeOff($invoice->fresh(), 'Client abroad, unreachable', $this->partner);
        $this->travelTo(CarbonImmutable::parse('2026-10-06 10:00', 'Asia/Manila'));
    }

    public function test_the_months_books_add_up(): void
    {
        $books = collect($this->getJson('/api/v1/books?month=2026-09')->assertOk()->json('books'))->keyBy('key');

        $this->assertSame(['cash' => 560_000, 'cwt' => 50_000, 'receivable' => 610_000], $books['cash-receipts']['totals']);
        $this->assertSame(['amount' => 100_000], $books['cash-disbursements']['totals']);
        $this->assertSame(['debit' => 1_120_000, 'credit' => 1_120_000], $books['general-journal']['totals']);
        $this->assertSame(['receipts' => 2_000_000, 'disbursements' => 0], $books['trust']['totals']);

        $october = collect($this->getJson('/api/v1/books?month=2026-10')->json('books'))->keyBy('key');
        $this->assertSame(['debit' => 510_000, 'credit' => 510_000], $october['general-journal']['totals'], 'The write-off: Dr Bad debts, Cr Accounts receivable.');
    }

    public function test_each_book_downloads_as_csv_and_pdf(): void
    {
        $csv = $this->get('/api/v1/books/cash-receipts?month=2026-09&format=csv')->assertOk()->streamedContent();
        $this->assertStringContainsString('"Ana Cruz",123-456-789-000', $csv);
        $this->assertStringContainsString('TOTAL,,,,,,5600.00,500.00,6100.00', $csv);

        $this->get('/api/v1/books/general-journal?month=2026-09&format=pdf')->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->get('/api/v1/books/payroll?month=2026-09&format=csv')->assertNotFound();

        $this->signIn(Role::Associate, $this->partner->firm()->first());
        $this->getJson('/api/v1/books?month=2026-09')->assertForbidden();
    }
}
