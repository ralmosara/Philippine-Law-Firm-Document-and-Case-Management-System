<?php

namespace Tests\Unit;

use App\Domain\Matters\Models\Client;
use App\Domain\Trust\Exceptions\InsufficientTrustFunds;
use App\Domain\Trust\Models\TrustAccount;
use App\Domain\Trust\Services\TrustLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class TrustLedgerServiceTest extends TestCase
{
    use RefreshDatabase;

    private TrustLedgerService $service;

    private TrustAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new TrustLedgerService;
        $this->account = TrustAccount::factory()->for(Client::factory())->create();
    }

    public function test_it_can_deposit_and_update_balance(): void
    {
        $transaction = $this->service->deposit($this->account, 50_000, 'Retainer');

        $this->assertSame(50_000, $transaction->balance_after_cents);
        $this->assertSame(50_000, $this->account->balance_cents, 'The passed model is refreshed in place.');
        $this->assertSame(50_000, $this->account->fresh()->balance_cents);
    }

    public function test_it_prevents_overdrawing_trust_account(): void
    {
        $this->service->deposit($this->account, 10_000, 'Deposit');

        try {
            $this->service->disburse($this->account, 20_000, 'Too much');
            $this->fail('Expected InsufficientTrustFunds.');
        } catch (InsufficientTrustFunds $e) {
            $this->assertStringContainsString('Insufficient trust funds', $e->getMessage());
        }

        $this->assertSame(10_000, $this->account->fresh()->balance_cents);
        $this->assertSame(1, $this->account->transactions()->count());
    }

    public function test_a_disbursement_may_bring_the_balance_to_exactly_zero(): void
    {
        $this->service->deposit($this->account, 10_000, 'Deposit');
        $this->service->disburse($this->account, 10_000, 'Refund');

        $this->assertSame(0, $this->account->fresh()->balance_cents);
        $this->assertSame([], $this->service->reconcile($this->account->fresh()));
    }

    public function test_amounts_must_be_positive(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service->deposit($this->account, -500, 'Negative deposit');
    }

    public function test_reconcile_verifies_every_running_balance(): void
    {
        foreach ([30_000, 20_000] as $amount) {
            $this->service->deposit($this->account, $amount, 'Deposit');
        }
        $this->service->disburse($this->account, 45_000, 'Fees');

        $this->assertSame([], $this->service->reconcile($this->account->fresh()));
        // Newest first: 30,000 -> 50,000 -> 5,000.
        $this->assertSame([5_000, 50_000, 30_000], $this->account->transactions()->pluck('balance_after_cents')->all());
    }
}
