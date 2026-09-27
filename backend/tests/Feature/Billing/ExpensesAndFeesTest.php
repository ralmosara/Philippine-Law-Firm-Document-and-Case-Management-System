<?php

namespace Tests\Feature\Billing;

use App\Domain\Billing\Models\Expense;
use App\Domain\Billing\Models\TimeEntry;
use App\Domain\Matters\Models\Client;
use App\Domain\Matters\Models\Firm;
use App\Domain\Matters\Models\Matter;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExpensesAndFeesTest extends TestCase
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

    public function test_expenses_are_recorded_against_a_matter(): void
    {
        $this->postJson('/api/v1/expenses', [
            'matter_id' => $this->matter->id, 'expense_date' => today()->toDateString(),
            'category' => 'filing_fee', 'description' => 'Docket fees, complaint', 'amount_cents' => 1_250_050,
        ])->assertCreated()
            ->assertJsonPath('category_label', 'Docket and filing fees')
            ->assertJsonPath('is_invoiced', false);

        $this->postJson('/api/v1/expenses', ['matter_id' => $this->matter->id, 'expense_date' => today()->toDateString(), 'category' => 'bribe', 'description' => 'x', 'amount_cents' => 1])
            ->assertStatus(422)->assertJsonValidationErrors('category');

        $this->getJson("/api/v1/expenses?matter_id={$this->matter->id}")
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('totals.amount_cents', 1_250_050);
        $this->getJson("/api/v1/matters/{$this->matter->id}")->assertJsonPath('unbilled_expenses_cents', 1_250_050);
    }

    public function test_an_invoice_bills_fees_with_vat_and_expenses_at_cost(): void
    {
        TimeEntry::factory()->for($this->matter)->create(['user_id' => $this->partner->id, 'minutes' => 60, 'rate_cents' => 500_000]);
        Expense::create(['firm_id' => $this->firm->id, 'matter_id' => $this->matter->id, 'user_id' => $this->partner->id, 'expense_date' => today(), 'category' => 'filing_fee', 'description' => 'Docket fees', 'amount_cents' => 300_000]);
        Expense::create(['firm_id' => $this->firm->id, 'matter_id' => $this->matter->id, 'user_id' => $this->partner->id, 'expense_date' => today(), 'category' => 'courier', 'description' => 'LBC', 'amount_cents' => 25_000, 'is_billable' => false]);

        $invoice = $this->postJson('/api/v1/invoices', [
            'matter_id' => $this->matter->id,
            'fee_lines' => [['description' => 'Acceptance fee', 'amount_cents' => 5_000_000]],
        ])->assertCreated()
            ->assertJsonPath('subtotal_cents', 5_500_000)   // time 5,000 + acceptance fee 50,000
            ->assertJsonPath('vat_cents', 660_000)           // 12% of fees only
            ->assertJsonPath('expenses_cents', 300_000)      // billable expense, no VAT
            ->assertJsonPath('total_cents', 6_460_000)
            ->json();

        $this->assertEqualsCanonicalizing(['fee', 'time', 'expense'], array_column($invoice['lines'], 'kind'));
        $this->assertSame(1, Expense::whereNotNull('invoice_id')->count()); // the non-billable one stays out

        // Invoiced expenses are locked, and voiding releases them.
        $billed = Expense::whereNotNull('invoice_id')->first();
        $this->patchJson("/api/v1/expenses/{$billed->id}", ['amount_cents' => 1])->assertForbidden();
        $this->postJson("/api/v1/invoices/{$invoice['id']}/void")->assertOk();
        $this->assertNull($billed->fresh()->invoice_id);
    }

    public function test_a_flat_fee_matter_can_be_billed_without_any_time(): void
    {
        $this->matter->update(['fee_arrangement' => 'flat', 'fixed_fee_cents' => 20_000_000]);

        $this->postJson('/api/v1/invoices', [
            'matter_id' => $this->matter->id, 'time_entry_ids' => [], 'expense_ids' => [],
            'fee_lines' => [['description' => 'Flat fee, first of two installments', 'amount_cents' => 10_000_000]],
        ])->assertCreated()->assertJsonPath('subtotal_cents', 10_000_000)->assertJsonCount(1, 'lines');

        $this->postJson('/api/v1/invoices', ['matter_id' => $this->matter->id])
            ->assertStatus(422)->assertJsonValidationErrors('time_entry_ids');
    }

    public function test_fee_arrangements_are_saved_on_the_matter(): void
    {
        $this->putJson("/api/v1/matters/{$this->matter->id}", [
            'client_id' => $this->matter->client_id, 'title' => $this->matter->title, 'case_type' => 'Civil',
            'fee_arrangement' => 'contingency', 'contingency_basis_points' => 2500, 'acceptance_fee_cents' => 3_000_000, 'appearance_fee_cents' => 500_000,
        ])->assertOk();

        $this->getJson("/api/v1/matters/{$this->matter->id}")
            ->assertJsonPath('fee_arrangement', 'contingency')
            ->assertJsonPath('fee_arrangement_label', 'Contingency (share of recovery)')
            ->assertJsonPath('contingency_basis_points', 2500)
            ->assertJsonPath('appearance_fee_cents', 500_000);
    }

    public function test_only_the_author_or_finance_can_change_an_expense(): void
    {
        $expense = Expense::create(['firm_id' => $this->firm->id, 'matter_id' => $this->matter->id, 'user_id' => $this->partner->id, 'expense_date' => today(), 'category' => 'travel', 'description' => 'Grab to court', 'amount_cents' => 45_000]);

        $this->signIn(Role::Associate, $this->firm);
        $this->deleteJson("/api/v1/expenses/{$expense->id}")->assertForbidden();

        $this->signIn(Role::Associate); // another firm
        $this->deleteJson("/api/v1/expenses/{$expense->id}")->assertNotFound();
    }
}
