<?php

namespace Tests\Feature\Billing;

use App\Domain\Billing\Models\DisbursementRequest;
use App\Domain\Billing\Models\Expense;
use App\Domain\Billing\Notifications\DisbursementNotice;
use App\Domain\Billing\Services\Disbursements;
use App\Domain\Documents\Models\MatterFile;
use App\Domain\Matters\Models\Client;
use App\Domain\Matters\Models\Firm;
use App\Domain\Matters\Models\Matter;
use App\Domain\Trust\Models\TrustAccount;
use App\Domain\Trust\Services\TrustLedgerService;
use App\Enums\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class DisbursementTest extends TestCase
{
    use RefreshDatabase;

    private Firm $firm;

    private User $associate;

    private User $partner;

    private Matter $matter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-09-28 09:00', 'Asia/Manila')); // a Monday
        $this->firm = Firm::factory()->create();
        $this->partner = User::factory()->role(Role::Partner)->create(['firm_id' => $this->firm->id]);
        $this->associate = $this->signIn(Role::Associate, $this->firm);
        $client = Client::factory()->for($this->firm)->create();
        $this->matter = Matter::factory()->for($client)->create(['firm_id' => $this->firm->id]);
    }

    private function ask(array $data = []): array
    {
        return $this->postJson("/api/v1/matters/{$this->matter->id}/disbursements", [
            'category' => 'filing_fee', 'description' => 'Docket fees for the complaint', 'amount_cents' => 500_000, 'source' => 'firm', ...$data,
        ])->assertCreated()->json();
    }

    private function as(User $user): void
    {
        $this->actingAs($user, 'web');
        $this->actingAs($user, 'sanctum');
    }

    public function test_a_cash_advance_goes_from_request_to_liquidation(): void
    {
        Notification::fake();
        $id = $this->ask()['id'];
        Notification::assertSentTo($this->partner, DisbursementNotice::class, fn ($n) => $n->event === 'requested');

        // The associate cannot approve, release or approve their own request.
        $this->postJson("/api/v1/disbursements/{$id}/approve")->assertForbidden();

        $this->as($this->partner);
        $this->postJson("/api/v1/disbursements/{$id}/release")->assertStatus(422); // not approved yet
        $this->postJson("/api/v1/disbursements/{$id}/approve")->assertOk()->assertJsonPath('status', 'approved');
        $this->postJson("/api/v1/disbursements/{$id}/release", ['reference' => 'CV-0142'])->assertOk()
            ->assertJsonPath('status', 'released')
            ->assertJsonPath('liquidation_due_on', '2026-10-05'); // 7 days, a Monday
        Notification::assertSentTo($this->associate, DisbursementNotice::class, fn ($n) => $n->event === 'released');

        $this->as($this->associate);
        $receipt = MatterFile::create(['firm_id' => $this->firm->id, 'matter_id' => $this->matter->id, 'original_name' => 'or.jpg', 'path' => 'x/or.jpg', 'mime_type' => 'image/jpeg', 'size_bytes' => 10, 'sha256' => str_repeat('c', 64)]);
        $this->postJson("/api/v1/disbursements/{$id}/liquidate", ['items' => [
            ['expense_date' => '2026-09-28', 'category' => 'filing_fee', 'description' => 'Docket fees, OR 1234567', 'amount_cents' => 452_000, 'receipt_file_id' => $receipt->id],
            ['expense_date' => '2026-09-28', 'category' => 'courier', 'description' => 'LBC to court', 'amount_cents' => 18_000],
        ]])->assertOk()
            ->assertJsonPath('status', 'liquidated')
            ->assertJsonPath('spent_cents', 470_000)
            ->assertJsonPath('returned_cents', 30_000)
            ->assertJsonPath('reimburse_cents', 0);

        // Receipts become billable expenses on the matter, locked to the advance.
        $expenses = Expense::where('disbursement_request_id', $id)->get();
        $this->assertCount(2, $expenses);
        $this->assertTrue($expenses->every->is_billable);
        $this->assertSame($receipt->id, $expenses->firstWhere('amount_cents', 452_000)->receipt_file_id);
        $this->patchJson("/api/v1/expenses/{$expenses[0]->id}", ['amount_cents' => 1])->assertForbidden();
    }

    public function test_advances_from_trust_post_to_the_client_ledger_and_are_not_billed_again(): void
    {
        $account = TrustAccount::factory()->create(['firm_id' => $this->firm->id, 'client_id' => $this->matter->client_id]);
        app(TrustLedgerService::class)->deposit($account, 1_000_000, 'Initial deposit');

        $id = $this->ask(['source' => 'trust', 'trust_account_id' => $account->id, 'amount_cents' => 300_000])['id'];

        $this->as($this->partner);
        $this->postJson("/api/v1/disbursements/{$id}/approve")->assertOk();
        $this->postJson("/api/v1/disbursements/{$id}/release")->assertOk();
        $this->assertSame(700_000, $account->refresh()->balance_cents);

        $this->as($this->associate);
        $this->postJson("/api/v1/disbursements/{$id}/liquidate", ['items' => [
            ['expense_date' => '2026-09-28', 'category' => 'sheriff_fee', 'description' => "Sheriff's fees", 'amount_cents' => 350_000],
        ]])->assertStatus(422)->assertJsonValidationErrors('items');

        $this->postJson("/api/v1/disbursements/{$id}/liquidate", ['items' => [
            ['expense_date' => '2026-09-28', 'category' => 'sheriff_fee', 'description' => "Sheriff's fees", 'amount_cents' => 250_000],
        ]])->assertOk()->assertJsonPath('returned_cents', 50_000);

        $this->assertSame(750_000, $account->refresh()->balance_cents); // unspent 500 back in trust
        $expense = Expense::where('disbursement_request_id', $id)->sole();
        $this->assertFalse($expense->is_billable);
        $this->assertStringEndsWith('(paid from trust deposit)', $expense->description);

        // Only an account of this matter's client can be used.
        $other = TrustAccount::factory()->create(['firm_id' => $this->firm->id, 'client_id' => Client::factory()->for($this->firm)->create()->id]);
        $this->postJson("/api/v1/matters/{$this->matter->id}/disbursements", [
            'category' => 'filing_fee', 'description' => 'X', 'amount_cents' => 100_00, 'source' => 'trust', 'trust_account_id' => $other->id,
        ])->assertStatus(422)->assertJsonValidationErrors('trust_account_id');
    }

    public function test_a_release_from_trust_cannot_overdraw_it(): void
    {
        $account = TrustAccount::factory()->create(['firm_id' => $this->firm->id, 'client_id' => $this->matter->client_id]);
        app(TrustLedgerService::class)->deposit($account, 100_000, 'Deposit');
        $id = $this->ask(['source' => 'trust', 'trust_account_id' => $account->id, 'amount_cents' => 300_000])['id'];

        $this->as($this->partner);
        $this->postJson("/api/v1/disbursements/{$id}/approve")->assertOk();
        $this->postJson("/api/v1/disbursements/{$id}/release")->assertStatus(422);
        $this->assertSame('approved', DisbursementRequest::find($id)->status);
        $this->assertSame(100_000, $account->refresh()->balance_cents);
    }

    public function test_rejection_cancellation_and_listing(): void
    {
        $a = $this->ask()['id'];
        $b = $this->ask(['description' => 'TSN for the 5 Oct hearing', 'category' => 'transcript', 'amount_cents' => 120_000])['id'];
        $this->postJson("/api/v1/disbursements/{$b}/cancel")->assertOk()->assertJsonPath('status', 'cancelled');

        $this->as($this->partner);
        $this->postJson("/api/v1/disbursements/{$a}/reject")->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/disbursements/{$a}/reject", ['reason' => 'Pay through the court e-payment instead'])->assertOk()->assertJsonPath('decision_note', 'Pay through the court e-payment instead');

        $closed = $this->getJson('/api/v1/disbursements?status=closed')->assertOk()->json('data');
        $this->assertCount(2, $closed);

        // Another associate sees only their own requests, but all of a matter's.
        $this->signIn(Role::Associate, $this->firm);
        $this->assertSame([], $this->getJson('/api/v1/disbursements?status=all')->json('data'));
        $this->assertCount(2, $this->getJson("/api/v1/disbursements?status=all&matter_id={$this->matter->id}")->json('data'));

        // Other firms see nothing.
        $this->signIn(Role::ManagingPartner, Firm::factory()->create());
        $this->postJson("/api/v1/disbursements/{$a}/cancel")->assertNotFound();
    }

    public function test_unliquidated_advances_are_chased(): void
    {
        $id = $this->ask()['id'];
        $this->as($this->partner);
        $this->postJson("/api/v1/disbursements/{$id}/approve")->assertOk();
        $this->postJson("/api/v1/disbursements/{$id}/release")->assertOk();

        Notification::fake();
        $service = app(Disbursements::class);
        $this->assertSame(0, $service->sendReminders(CarbonImmutable::parse('2026-10-02')));
        $this->assertSame(1, $service->sendReminders(CarbonImmutable::parse('2026-10-05'))); // due today: the requester
        $this->assertSame(2, $service->sendReminders(CarbonImmutable::parse('2026-10-06'))); // overdue: requester and partner
        $this->assertSame(0, $service->sendReminders(CarbonImmutable::parse('2026-10-07')));
        Notification::assertSentTo($this->partner, DisbursementNotice::class, fn ($n) => $n->event === 'liquidation_overdue');
    }
}
