<?php

namespace Tests\Feature\Billing;

use App\Domain\Matters\Models\Client;
use App\Domain\Matters\Models\Firm;
use App\Domain\Trust\Models\TrustAccount;
use App\Domain\Trust\Notifications\TrustReconciliationDue;
use App\Domain\Trust\Services\TrustLedgerService;
use App\Domain\Trust\Services\TrustReconciliations;
use App\Enums\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class TrustReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private Firm $firm;

    private User $partner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->firm = Firm::factory()->create();
        $this->partner = $this->signIn(Role::ManagingPartner, $this->firm);
        $ledger = app(TrustLedgerService::class);

        // September: ₱50,000 and ₱20,000 deposited, ₱5,000 paid out. October: a further ₱10,000.
        $this->travelTo(CarbonImmutable::parse('2026-09-10 10:00'));
        $a = TrustAccount::factory()->for($this->firm)->create(['client_id' => Client::factory()->for($this->firm)->create(['name' => 'Ana Cruz'])->id]);
        $b = TrustAccount::factory()->for($this->firm)->create(['client_id' => Client::factory()->for($this->firm)->create(['name' => 'Ben Reyes'])->id]);
        $ledger->deposit($a, 5_000_000, 'Acceptance', by: $this->partner);
        $ledger->deposit($b, 2_000_000, 'Filing fees', by: $this->partner);
        $ledger->disburse($a, 500_000, 'Docket fees', by: $this->partner);
        $this->travelTo(CarbonImmutable::parse('2026-10-03 10:00'));
        $ledger->deposit($b, 1_000_000, 'Top-up', by: $this->partner);
        $this->travelTo(CarbonImmutable::parse('2026-10-06 09:00'));
    }

    private function prepare(array $data = []): array
    {
        return $this->postJson('/api/v1/trust-reconciliations', [
            'period_end' => '2026-09-30',
            'bank_account' => 'BPI Client Trust 1234-5678-90',
            // The bank shows ₱60,000: the ₱20,000 deposit arrived on 1 October, and the ₱5,000 cheque has not cleared.
            'statement_balance_cents' => 4_500_000 + 500_000,
            'deposits_in_transit' => [['description' => 'Deposit of Sept 30', 'amount_cents' => 2_000_000]],
            'outstanding_checks' => [['description' => 'Cheque 000123', 'amount_cents' => 500_000]],
            ...$data,
        ])->assertCreated()->json();
    }

    public function test_the_three_figures_agree_as_of_the_end_of_the_month(): void
    {
        $this->getJson('/api/v1/trust-reconciliations')->assertOk()
            ->assertJsonPath('next.period_end', '2026-09-30')
            ->assertJsonPath('next.ledger_cents', 6_500_000);

        $r = $this->prepare();
        $this->assertSame([6_500_000, 6_500_000, 6_500_000, 0, true], [$r['adjusted_bank_cents'], $r['ledger_cents'], $r['client_total_cents'], $r['difference_cents'], $r['balances']]);

        $this->postJson("/api/v1/trust-reconciliations/{$r['id']}/sign-off")->assertOk()->assertJsonPath('signed_off_by', $this->partner->name);
        $this->get("/api/v1/trust-reconciliations/{$r['id']}/pdf")->assertOk()->assertHeader('content-type', 'application/pdf');

        $this->postJson('/api/v1/trust-reconciliations', ['period_end' => '2026-09-30', 'bank_account' => 'x', 'statement_balance_cents' => 1])
            ->assertJsonValidationErrors('period_end');
    }

    public function test_a_difference_needs_an_explanation_to_sign_off(): void
    {
        $r = $this->prepare(['statement_balance_cents' => 4_900_000]);
        $this->assertSame(-100_000, $r['difference_cents']);
        $this->assertFalse($r['balances']);

        $this->postJson("/api/v1/trust-reconciliations/{$r['id']}/sign-off")->assertJsonValidationErrors('notes');
        $this->postJson("/api/v1/trust-reconciliations/{$r['id']}/sign-off", ['notes' => 'Bank charge of ₱1,000 debited in error; bank asked to reverse it.'])->assertOk();
    }

    public function test_the_current_month_cannot_be_reconciled_yet_and_associates_cannot_reconcile(): void
    {
        $this->postJson('/api/v1/trust-reconciliations', ['period_end' => '2026-10-31', 'bank_account' => 'x', 'statement_balance_cents' => 1])->assertJsonValidationErrors('period_end');

        $this->signIn(Role::Associate, $this->firm);
        $this->getJson('/api/v1/trust-reconciliations')->assertForbidden();
    }

    public function test_partners_are_reminded_on_the_tenth_until_it_is_signed_off(): void
    {
        Notification::fake();
        $reconciliations = app(TrustReconciliations::class);

        $this->assertSame(0, $reconciliations->remind(CarbonImmutable::parse('2026-10-09')));
        $this->assertSame(1, $reconciliations->remind(CarbonImmutable::parse('2026-10-10')));
        Notification::assertSentTo($this->partner, TrustReconciliationDue::class);

        $r = $this->prepare();
        $this->postJson("/api/v1/trust-reconciliations/{$r['id']}/sign-off")->assertOk();
        $this->assertSame(0, $reconciliations->remind(CarbonImmutable::parse('2026-10-10')));
    }
}
