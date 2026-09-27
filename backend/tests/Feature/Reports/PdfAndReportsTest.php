<?php

namespace Tests\Feature\Reports;

use App\Domain\Billing\Models\Expense;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\TimeEntry;
use App\Domain\Billing\Services\InvoiceGenerator;
use App\Domain\Documents\Actions\CreateDocumentVersion;
use App\Domain\Documents\Models\Document;
use App\Domain\Matters\Models\Client;
use App\Domain\Matters\Models\Firm;
use App\Domain\Matters\Models\Matter;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Smalot\PdfParser\Parser;
use Tests\TestCase;

class PdfAndReportsTest extends TestCase
{
    use RefreshDatabase;

    private Firm $firm;

    private Client $client;

    private Matter $matter;

    private User $partner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->firm = Firm::factory()->create(['name' => 'Santos & Reyes Law', 'vat_registered' => true, 'tin' => '123-456-789-000']);
        $this->client = Client::factory()->for($this->firm)->withPortal('secret-pass')->create(['name' => 'Juan Dela Cruz']);
        $this->partner = $this->signIn(Role::Partner, $this->firm, ['name' => 'Atty. Maria Santos']);
        $this->matter = Matter::factory()->for($this->client)->create(['title' => 'Dela Cruz v. Reyes', 'responsible_lawyer_id' => $this->partner->id]);
    }

    public function test_a_document_downloads_as_a_pdf_on_letterhead(): void
    {
        $document = Document::create(['firm_id' => $this->firm->id, 'matter_id' => $this->matter->id, 'title' => 'Demand Letter', 'created_by' => $this->partner->id, 'shared_with_client' => true]);
        app(CreateDocumentVersion::class)->execute($document, "Dear Mr. Reyes,\n\nWe demand payment of PHP 500,000.00.", $this->partner);

        $response = $this->get("/api/v1/documents/{$document->id}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('attachment; filename="demand-letter.pdf"', $response->headers->get('Content-Disposition'));

        $text = $this->pdfText($response->getContent());
        $this->assertStringContainsString('Santos & Reyes Law', $text);
        $this->assertStringContainsString('We demand payment of PHP 500,000.00.', $text);

        // The client can download shared documents only.
        $this->actingAs($this->client, 'client');
        $this->get("/api/portal/documents/{$document->id}/pdf")->assertOk();
        $document->update(['shared_with_client' => false]);
        $this->get("/api/portal/documents/{$document->id}/pdf")->assertNotFound();
    }

    public function test_an_invoice_downloads_as_a_billing_statement(): void
    {
        $invoice = $this->issuedInvoice(60, 500_000);
        Expense::create(['firm_id' => $this->firm->id, 'matter_id' => $this->matter->id, 'user_id' => $this->partner->id, 'expense_date' => today(), 'category' => 'filing_fee', 'description' => 'Docket fees', 'amount_cents' => 120_000]);

        $text = $this->pdfText($this->get("/api/v1/invoices/{$invoice->id}/pdf")->assertOk()->getContent());

        $this->assertStringContainsString('BILLING STATEMENT', $text);
        $this->assertStringContainsString($invoice->number, $text);
        $this->assertStringContainsString('₱5,600.00', $text); // 5,000 + 12% VAT
        $this->assertStringContainsString('Juan Dela Cruz', $text);

        $this->signIn(Role::Paralegal, $this->firm);
        $this->get("/api/v1/invoices/{$invoice->id}/pdf")->assertForbidden();
    }

    public function test_aged_receivables_buckets_unpaid_invoices_by_days_past_due(): void
    {
        $current = $this->issuedInvoice(60, 100_000);                        // due in 30 days
        $late = $this->issuedInvoice(60, 200_000);
        $late->forceFill(['due_at' => today()->subDays(45)])->save();         // 31-60 bucket
        $paid = $this->issuedInvoice(60, 300_000);
        app(InvoiceGenerator::class)->markPaid($paid, $this->partner, 'OR-1');

        $this->getJson('/api/v1/reports/aged-receivables')
            ->assertOk()
            ->assertJsonCount(1, 'rows')
            ->assertJsonPath('rows.0.client', 'Juan Dela Cruz')
            ->assertJsonPath('rows.0.current', $current->total_cents)
            ->assertJsonPath('rows.0.d31_60', $late->total_cents)
            ->assertJsonPath('totals.total', $current->total_cents + $late->total_cents);
    }

    public function test_collections_and_profitability_reports(): void
    {
        $paid = $this->issuedInvoice(120, 500_000); // 10,000 fees
        app(InvoiceGenerator::class)->markPaid($paid, $this->partner, 'OR-2');
        TimeEntry::factory()->for($this->matter)->create(['user_id' => $this->partner->id, 'minutes' => 30, 'rate_cents' => 500_000]); // 2,500 unbilled

        $this->getJson('/api/v1/reports/collections?from='.today()->subMonth()->toDateString())
            ->assertJsonPath('rows.0.lawyer', 'Atty. Maria Santos')
            ->assertJsonPath('rows.0.fees', 1_000_000)
            ->assertJsonPath('totals.total', $paid->total_cents);

        $this->getJson('/api/v1/reports/matter-profitability')
            ->assertJsonPath('rows.0.title', 'Dela Cruz v. Reyes')
            ->assertJsonPath('rows.0.recorded', 1_250_000)
            ->assertJsonPath('rows.0.billed', 1_000_000)
            ->assertJsonPath('rows.0.collected', 1_000_000)
            ->assertJsonPath('rows.0.unbilled', 250_000)
            ->assertJsonPath('rows.0.collection_rate', 100);
    }

    public function test_reports_export_as_csv_and_are_restricted_to_finance(): void
    {
        $this->issuedInvoice(60, 100_000);
        $this->client->update(['name' => '=HYPERLINK("http://evil")']);

        $csv = $this->get('/api/v1/reports/aged-receivables?format=csv')->assertOk()->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBFClient,Invoices", $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv);   // formula neutralised
        $this->assertStringContainsString('TOTAL', $csv);
        $this->assertStringContainsString('1120.00', $csv);        // pesos, not centavos

        $this->signIn(Role::Associate, $this->firm);
        $this->getJson('/api/v1/reports/collections')->assertForbidden();
    }

    private function issuedInvoice(int $minutes, int $rate): Invoice
    {
        TimeEntry::factory()->for($this->matter)->create(['user_id' => $this->partner->id, 'minutes' => $minutes, 'rate_cents' => $rate]);
        $generator = app(InvoiceGenerator::class);

        return $generator->issue($generator->generateForMatter($this->matter, $this->partner));
    }

    private function pdfText(string $pdf): string
    {
        return (new Parser)->parseContent($pdf)->getText();
    }
}
