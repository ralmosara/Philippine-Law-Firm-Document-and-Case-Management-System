<?php

namespace Tests\Feature\Imports;

use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\InvoicePayment;
use App\Domain\Billing\Models\TimeEntry;
use App\Domain\EInvoicing\EInvoice;
use App\Domain\Imports\Importers\TimeEntryImporter;
use App\Domain\Matters\Models\Client;
use App\Domain\Matters\Models\Firm;
use App\Domain\Matters\Models\Matter;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class BillingHistoryImportTest extends TestCase
{
    use RefreshDatabase;

    private Firm $firm;

    private User $partner;

    private Matter $matter;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->firm = Firm::factory()->create(['vat_registered' => true, 'einvoicing_enabled' => true, 'tin' => '123-456-789-000']);
        $this->partner = $this->signIn(Role::ManagingPartner, $this->firm, ['email' => 'maria@firm.ph', 'name' => 'Atty. Maria Santos', 'hourly_rate_cents' => 500000]);
        $this->matter = Matter::factory()->for(Client::factory()->for($this->firm)->state(['name' => 'Kalayaan Realty']))->create(['reference' => 'M-2025-0042', 'case_number' => 'Civil Case No. R-MKT-25-0042']);
    }

    private function csv(array $rows): UploadedFile
    {
        $handle = fopen('php://temp', 'r+');
        foreach ($rows as $row) {
            fputcsv($handle, $row, escape: '');
        }
        rewind($handle);

        return UploadedFile::fake()->createWithContent('import.csv', "\xEF\xBB\xBF".stream_get_contents($handle));
    }

    private function preview(string $type, array $rows): TestResponse
    {
        return $this->post('/api/v1/imports', ['type' => $type, 'file' => $this->csv($rows)], ['Accept' => 'application/json']);
    }

    private function importInvoices(): int
    {
        $id = $this->preview('invoices', [
            ['Invoice No', 'Case No', 'Invoice Date', 'Fees', 'Expenses', 'Total', 'Amount Paid', 'Tax Withheld', 'Date Paid', 'Remarks'],
            ['SI-2025-0457', 'Civil Case No. R-MKT-25-0042', '03/15/2025', '50,000.00', '2,500.00', '58,500.00', '53,500.00', '5,000.00', '04/10/2025', 'Retainer, March 2025'],
            ['SI-2025-0501', 'M-2025-0042', '2025-06-01', '20000', '', '', '', '', '', ''],
        ])->assertCreated()->assertJsonPath('summary.ready', 2)->json('id');
        $this->postJson("/api/v1/imports/{$id}/commit")->assertOk()->assertJsonPath('summary.created', 2);

        return $id;
    }

    public function test_old_invoices_are_imported_with_their_payments_and_nothing_is_sent(): void
    {
        $this->importInvoices();

        $paid = Invoice::where('number', 'SI-2025-0457')->firstOrFail();
        $this->assertSame('paid', $paid->status->value);
        $this->assertSame(6_000_00, $paid->vat_cents, '12% of the fees, computed because the column was absent.');
        $this->assertSame(58_500_00, $paid->total_cents);
        $this->assertSame(5_000_00, $paid->withholding_cents);
        $this->assertSame('2025-03-15', $paid->issued_at->toDateString());
        $this->assertSame('2025-04-10', $paid->paid_at->toDateString());
        $this->assertSame(1, InvoicePayment::where('invoice_id', $paid->id)->count());

        $open = Invoice::where('number', 'SI-2025-0501')->firstOrFail();
        $this->assertSame('issued', $open->status->value);
        $this->assertSame(22_400_00, $open->total_cents);
        $this->assertSame('2025-07-01', $open->due_at->toDateString(), '30 days after issue.');
        $this->assertNotNull($open->reminders_paused_at, 'No surprise reminders on old invoices.');

        $this->assertSame(0, EInvoice::count(), 'History is not e-invoiced again.');
        Notification::assertNothingSent();

        // The firm's own numbering carries on.
        $this->assertMatchesRegularExpression('/^INV-\d{4}-00001$/', Invoice::nextNumber($this->firm->id, (int) now()->year));
    }

    public function test_invoice_rows_are_checked(): void
    {
        Invoice::create(['firm_id' => $this->firm->id, 'client_id' => $this->matter->client_id, 'matter_id' => $this->matter->id, 'number' => 'SI-2025-0001', 'subtotal_cents' => 100000, 'vat_cents' => 12000, 'expenses_cents' => 0, 'total_cents' => 112000]);

        $this->preview('invoices', [
            ['Invoice number', 'Matter', 'Issued on', 'Professional fees', 'VAT', 'Total', 'Amount paid'],
            ['SI-2025-0001', 'M-2025-0042', '01/05/2025', '1000', '120', '', ''],                // already in the system
            ['SI-2025-0002', 'M-2099-9999', '01/05/2025', '1000', '', '', ''],                   // unknown matter
            ['SI-2025-0003', 'M-2025-0042', '01/05/2025', '1000', '120', '2000', ''],            // total mismatch
            ['SI-2025-0004', 'M-2025-0042', '01/05/2025', '1000', '120', '', '5000'],            // overpaid
            ['SI-2025-0005', 'M-2025-0042', 'next week', '1000', '', '', ''],                    // bad date
            ['SI-2025-0006', 'M-2025-0042', '01/05/2025', '1000', '0', '', ''],                  // ok, zero VAT given
            ['si-2025-0006', 'M-2025-0042', '01/05/2025', '1000', '0', '', ''],                  // repeated in the file
        ])->assertCreated()
            ->assertJsonPath('summary.ready', 1)
            ->assertJsonPath('summary.duplicate', 2)
            ->assertJsonPath('summary.error', 4)
            ->assertJsonPath('rows.0.messages.0', 'Invoice SI-2025-0001 is already in the system.')
            ->assertJsonPath('rows.2.messages.0', 'Total ₱2,000.00 does not equal fees + VAT + expenses (₱1,120.00).')
            ->assertJsonPath('rows.3.messages.0', 'Paid and withheld (₱5,000.00) are more than the total (₱1,120.00).');
    }

    public function test_time_entries_are_imported_billed_or_unbilled(): void
    {
        $this->importInvoices();
        User::factory()->create(['firm_id' => $this->firm->id, 'name' => 'Atty. Jose Cruz', 'email' => 'jose@firm.ph', 'hourly_rate_cents' => 300000]);

        $id = $this->preview('time_entries', [
            ['Case No', 'Work Date', 'Timekeeper', 'Hrs', 'Narrative', 'Rate', 'Billable', 'Invoice No'],
            ['M-2025-0042', '03/10/2025', 'maria@firm.ph', '2.5', 'Drafted the complaint', '', 'yes', 'SI-2025-0457'],
            ['M-2025-0042', '06/20/2025', 'Atty. Jose Cruz', '1:30', 'Research on prescription', '', '', ''],
            ['M-2025-0042', '06/21/2025', 'jose@firm.ph', '45m', 'Internal case review', '', 'no', ''],
            ['M-2025-0042', '06/22/2025', 'jose@firm.ph', '3', 'Hearing preparation', '4,000', 'yes', 'SI-9999'],       // unknown invoice
            ['M-2025-0042', '06/23/2025', 'nobody@firm.ph', '1', 'Call', '', '', ''],                                // unknown user
            ['M-2025-0042', '06/24/2025', 'jose@firm.ph', 'lots', 'Call', '', '', ''],                               // bad hours
        ])->assertCreated()->assertJsonPath('summary.ready', 3)->assertJsonPath('summary.error', 3)->json('id');
        $this->postJson("/api/v1/imports/{$id}/commit")->assertOk()->assertJsonPath('summary.created', 3);

        $billed = TimeEntry::where('description', 'Drafted the complaint')->firstOrFail();
        $this->assertSame(150, $billed->minutes);
        $this->assertSame(1_250_000, $billed->amount_cents, "2.5 h at the lawyer's ₱5,000 rate.");
        $this->assertSame(Invoice::where('number', 'SI-2025-0457')->value('id'), $billed->invoice_id);

        $unbilled = TimeEntry::where('description', 'Research on prescription')->firstOrFail();
        $this->assertSame(90, $unbilled->minutes);
        $this->assertNull($unbilled->invoice_id);
        $this->assertSame(1, TimeEntry::where('matter_id', $this->matter->id)->unbilled()->count(), 'Ready to invoice here.');
        $this->assertSame(0, TimeEntry::where('description', 'Internal case review')->value('amount_cents'));

        // Undo: the time first (it is tied to an imported invoice), then the invoices.
        $this->postJson("/api/v1/imports/{$id}/undo")->assertOk()->assertJsonPath('undone', 3);
        $this->assertSame(0, TimeEntry::count());
    }

    public function test_undo_keeps_what_was_worked_on_since(): void
    {
        $invoices = $this->importInvoices();
        $open = Invoice::where('number', 'SI-2025-0501')->firstOrFail();
        $this->postJson("/api/v1/invoices/{$open->id}/payments", ['amount_cents' => 10_000_00, 'method' => 'bank_transfer', 'received_on' => today()->toDateString()])->assertCreated();

        $result = $this->postJson("/api/v1/imports/{$invoices}/undo")->assertOk();
        $this->assertSame(1, $result->json('undone'));
        $this->assertStringContainsString('Payments have been recorded on SI-2025-0501 since the import.', implode(' ', $result->json('kept')));
        $this->assertTrue(Invoice::where('number', 'SI-2025-0501')->exists());
        $this->assertFalse(Invoice::where('number', 'SI-2025-0457')->exists());
    }

    public function test_hours_are_read_the_ways_offices_write_them(): void
    {
        foreach (['1.5' => 90, '1,5' => 90, '1:30' => 90, '90m' => 90, '2h' => 120, '2 hrs' => 120, '0.25' => 15, 'x' => null, '' => null] as $text => $minutes) {
            $this->assertSame($minutes, TimeEntryImporter::minutes((string) $text), "\"{$text}\"");
        }
    }

    public function test_only_finance_staff_import_billing_history(): void
    {
        $this->signIn(Role::Associate, $this->firm);
        $this->preview('invoices', [['Invoice number', 'Matter', 'Issued on', 'Professional fees'], ['X-1', 'M-2025-0042', '01/05/2025', '1000']])->assertForbidden();
    }
}
