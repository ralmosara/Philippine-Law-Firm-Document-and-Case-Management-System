<?php

namespace Tests\Feature\Billing;

use App\Domain\Matters\Models\Client;
use App\Domain\Matters\Models\Firm;
use App\Domain\Matters\Models\Matter;
use App\Domain\Trust\Models\TrustAccount;
use App\Domain\Trust\Models\TrustTransaction;
use App\Enums\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

class TrustAccountTest extends TestCase
{
    use RefreshDatabase;

    private Firm $firm;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->firm = Firm::factory()->create();
        $this->client = Client::factory()->for($this->firm)->create();
    }

    public function test_postings_maintain_a_running_balance(): void
    {
        $this->signIn(Role::Partner, $this->firm);
        $id = $this->postJson('/api/v1/trust-accounts', ['client_id' => $this->client->id])
            ->assertCreated()
            ->assertJsonPath('account_number', 'TA-00001')
            ->assertJsonPath('balance_cents', 0)
            ->json('id');

        $this->postJson("/api/v1/trust-accounts/{$id}/transactions", [
            'type' => 'deposit', 'amount_cents' => 5_000_000, 'description' => 'Retainer deposit', 'reference' => 'OR-1001',
        ])->assertCreated()->assertJsonPath('transaction.balance_after_cents', 5_000_000);

        $this->postJson("/api/v1/trust-accounts/{$id}/transactions", [
            'type' => 'disbursement', 'amount_cents' => 500_000, 'description' => 'RTC filing fees',
        ])->assertCreated()
            ->assertJsonPath('transaction.balance_after_cents', 4_500_000)
            ->assertJsonPath('account.balance_cents', 4_500_000);

        $this->getJson("/api/v1/trust-accounts/{$id}/transactions")
            ->assertJsonPath('data.0.signed_amount_cents', -500_000)
            ->assertJsonPath('data.1.signed_amount_cents', 5_000_000);

        $this->getJson("/api/v1/trust-accounts/{$id}/reconcile")->assertJson(['reconciled' => true, 'problems' => []]);
    }

    public function test_overdrawing_is_rejected_and_leaves_no_trace(): void
    {
        $this->signIn(Role::Partner, $this->firm);
        $account = TrustAccount::factory()->for($this->client)->create();

        $this->postJson("/api/v1/trust-accounts/{$account->id}/transactions", [
            'type' => 'disbursement', 'amount_cents' => 1, 'description' => 'Too much',
        ])->assertStatus(422)
            ->assertJsonPath('status', 'error')
            ->assertJsonValidationErrors('amount');

        $this->assertSame(0, $account->fresh()->balance_cents);
        $this->assertDatabaseCount('trust_transactions', 0);
    }

    public function test_the_matter_must_belong_to_the_client(): void
    {
        $this->signIn(Role::Partner, $this->firm);
        $otherMatter = Matter::factory()->for(Client::factory()->for($this->firm))->create();

        $this->postJson('/api/v1/trust-accounts', ['client_id' => $this->client->id, 'matter_id' => $otherMatter->id])
            ->assertStatus(422);
    }

    public function test_ledger_rows_cannot_be_edited(): void
    {
        $this->signIn(Role::Partner, $this->firm);
        $account = TrustAccount::factory()->for($this->client)->create();
        $this->postJson("/api/v1/trust-accounts/{$account->id}/transactions", ['type' => 'deposit', 'amount_cents' => 100, 'description' => 'x']);

        $this->expectException(LogicException::class);
        TrustTransaction::first()->update(['amount_cents' => 1_000_000]);
    }

    public function test_reconciliation_detects_tampering_below_the_application(): void
    {
        $this->signIn(Role::Partner, $this->firm);
        $account = TrustAccount::factory()->for($this->client)->create();
        $this->postJson("/api/v1/trust-accounts/{$account->id}/transactions", ['type' => 'deposit', 'amount_cents' => 10_000, 'description' => 'x']);

        DB::table('trust_accounts')->where('id', $account->id)->update(['balance_cents' => 99_999]);

        $this->getJson("/api/v1/trust-accounts/{$account->id}/reconcile")
            ->assertJsonPath('reconciled', false)
            ->assertJsonCount(1, 'problems');
        $this->artisan('trust:reconcile')->assertFailed();
    }

    public function test_only_empty_accounts_can_be_closed(): void
    {
        $this->signIn(Role::Partner, $this->firm);
        $account = TrustAccount::factory()->for($this->client)->create();
        $this->postJson("/api/v1/trust-accounts/{$account->id}/transactions", ['type' => 'deposit', 'amount_cents' => 100, 'description' => 'x']);

        $this->postJson("/api/v1/trust-accounts/{$account->id}/close")->assertStatus(422);

        $this->postJson("/api/v1/trust-accounts/{$account->id}/transactions", ['type' => 'disbursement', 'amount_cents' => 100, 'description' => 'Refund to client']);
        $this->postJson("/api/v1/trust-accounts/{$account->id}/close")->assertOk()->assertJsonPath('status', 'closed');

        $this->postJson("/api/v1/trust-accounts/{$account->id}/transactions", ['type' => 'deposit', 'amount_cents' => 100, 'description' => 'x'])
            ->assertStatus(422);
    }
}
