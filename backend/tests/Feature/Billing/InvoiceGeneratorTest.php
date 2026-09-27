<?php

namespace Tests\Feature\Billing;

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
use Tests\TestCase;

class InvoiceGeneratorTest extends TestCase
{
    use RefreshDatabase;

    private Firm $firm;

    private Matter $matter;

    private User $partner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->firm = Firm::factory()->create();
        $this->matter = Matter::factory()->for(Client::factory()->for($this->firm))->create();
        $this->partner = $this->signIn(Role::Partner, $this->firm, ['hourly_rate_cents' => 500_000]);
    }

    public function test_time_is_billed_at_the_lawyers_rate_and_amounts_are_derived(): void
    {
        $this->postJson('/api/v1/time-entries', [
            'matter_id' => $this->matter->id, 'work_date' => today()->toDateString(), 'minutes' => 50,
            'description' => 'Drafted answer', 'amount_cents' => 1, // ignored: never accepted from input
        ])->assertCreated()
            ->assertJsonPath('rate_cents', 500_000)
            ->assertJsonPath('amount_cents', 416_667); // 50/60 x 5,000.00, rounded half-up
    }

    public function test_associates_cannot_override_their_rate(): void
    {
        $this->signIn(Role::Associate, $this->firm, ['hourly_rate_cents' => 300_000]);

        $this->postJson('/api/v1/time-entries', [
            'matter_id' => $this->matter->id, 'work_date' => today()->toDateString(), 'minutes' => 60,
            'description' => 'Research', 'rate_cents' => 9_999_999,
        ])->assertCreated()->assertJsonPath('rate_cents', 300_000);
    }

    public function test_invoice_totals_include_vat_and_claim_the_time_entries(): void
    {
        TimeEntry::factory()->for($this->matter)->create(['user_id' => $this->partner->id, 'minutes' => 120, 'rate_cents' => 500_000]);
        TimeEntry::factory()->for($this->matter)->create(['user_id' => $this->partner->id, 'minutes' => 60, 'rate_cents' => 200_000]);
        TimeEntry::factory()->for($this->matter)->create(['user_id' => $this->partner->id, 'minutes' => 60, 'rate_cents' => 200_000, 'is_billable' => false]);

        $response = $this->postJson('/api/v1/invoices', ['matter_id' => $this->matter->id])
            ->assertCreated()
            ->assertJsonPath('status', 'draft')
            ->assertJsonPath('subtotal_cents', 1_200_000)
            ->assertJsonPath('vat_cents', 144_000)
            ->assertJsonPath('total_cents', 1_344_000)
            ->assertJsonCount(2, 'lines');

        $this->assertMatchesRegularExpression('/^INV-\d{4}-00001$/', $response->json('number'));
        $this->assertSame(0, TimeEntry::where('matter_id', $this->matter->id)->unbilled()->count());

        // Nothing left to bill: the same hours can never be invoiced twice.
        $this->postJson('/api/v1/invoices', ['matter_id' => $this->matter->id])->assertStatus(422);
    }

    public function test_non_vat_firms_bill_without_vat(): void
    {
        $this->firm->update(['vat_registered' => false]);
        TimeEntry::factory()->for($this->matter)->create(['user_id' => $this->partner->id, 'minutes' => 60, 'rate_cents' => 100_000]);

        $this->postJson('/api/v1/invoices', ['matter_id' => $this->matter->id])
            ->assertJsonPath('vat_cents', 0)
            ->assertJsonPath('total_cents', 100_000);
    }

    public function test_invoiced_time_cannot_be_edited(): void
    {
        $entry = TimeEntry::factory()->for($this->matter)->create(['user_id' => $this->partner->id]);
        $this->postJson('/api/v1/invoices', ['matter_id' => $this->matter->id])->assertCreated();

        $this->putJson("/api/v1/time-entries/{$entry->id}", ['minutes' => 1])->assertForbidden();
        $this->deleteJson("/api/v1/time-entries/{$entry->id}")->assertForbidden();
    }

    public function test_the_invoice_lifecycle_and_voiding_releases_time(): void
    {
        TimeEntry::factory()->for($this->matter)->create(['user_id' => $this->partner->id]);
        $id = $this->postJson('/api/v1/invoices', ['matter_id' => $this->matter->id])->json('id');

        $this->postJson("/api/v1/invoices/{$id}/pay")->assertStatus(422); // must be issued first
        $this->postJson("/api/v1/invoices/{$id}/issue")->assertOk()->assertJsonPath('status', 'issued');
        $this->postJson("/api/v1/invoices/{$id}/void")->assertOk()->assertJsonPath('status', 'void');
        $this->postJson("/api/v1/invoices/{$id}/issue")->assertStatus(422);

        $this->assertSame(1, TimeEntry::where('matter_id', $this->matter->id)->unbilled()->count());
    }

    public function test_paying_from_trust_disburses_the_exact_total(): void
    {
        TimeEntry::factory()->for($this->matter)->create(['user_id' => $this->partner->id, 'minutes' => 60, 'rate_cents' => 100_000]);
        $account = TrustAccount::factory()->create(['client_id' => $this->matter->client_id]);
        app(TrustLedgerService::class)->deposit($account, 1_000_000, 'Deposit');

        $invoice = app(InvoiceGenerator::class)->generateForMatter($this->matter, $this->partner);
        $this->postJson("/api/v1/invoices/{$invoice->id}/issue");

        $this->postJson("/api/v1/invoices/{$invoice->id}/pay", ['trust_account_id' => $account->id])
            ->assertOk()
            ->assertJsonPath('status', 'paid');

        $this->assertSame(1_000_000 - 112_000, $account->fresh()->balance_cents);
        $this->assertDatabaseHas('trust_transactions', ['trust_account_id' => $account->id, 'type' => 'disbursement', 'amount_cents' => 112_000, 'reference' => $invoice->number]);
    }

    public function test_trust_funds_cannot_pay_another_clients_invoice(): void
    {
        TimeEntry::factory()->for($this->matter)->create(['user_id' => $this->partner->id]);
        $otherAccount = TrustAccount::factory()->for(Client::factory()->for($this->firm))->create();
        app(TrustLedgerService::class)->deposit($otherAccount, 100_000_000, 'Deposit');

        $invoice = app(InvoiceGenerator::class)->issue(app(InvoiceGenerator::class)->generateForMatter($this->matter, $this->partner));

        $this->postJson("/api/v1/invoices/{$invoice->id}/pay", ['trust_account_id' => $otherAccount->id])
            ->assertStatus(422)->assertJsonValidationErrors('trust_account_id');

        $this->assertSame('issued', $invoice->fresh()->status->value);
        $this->assertSame(100_000_000, $otherAccount->fresh()->balance_cents);
    }

    public function test_vat_rounding(): void
    {
        $generator = app(InvoiceGenerator::class);

        $this->assertSame(12, $generator->vatOn(100));
        $this->assertSame(1, $generator->vatOn(5));   // 0.6 -> 1
        $this->assertSame(0, $generator->vatOn(4));   // 0.48 -> 0
        $this->assertSame(120_000_000, $generator->vatOn(1_000_000_000));
    }
}
