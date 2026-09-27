<?php

namespace Tests\Feature\Api;

use App\Domain\Matters\Models\Client;
use App\Domain\Matters\Models\Firm;
use App\Domain\Matters\Models\Matter;
use App\Domain\Trust\Models\TrustAccount;
use App\Domain\Trust\Services\TrustLedgerService;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Role-based limits inside a firm.
 */
class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private Firm $firm;

    protected function setUp(): void
    {
        parent::setUp();
        $this->firm = Firm::factory()->create();
    }

    public function test_only_the_managing_partner_manages_users(): void
    {
        $this->signIn(Role::Partner, $this->firm);

        $this->postJson('/api/v1/users', [
            'name' => 'New Associate', 'email' => 'new@firm.ph', 'password' => 'password123', 'role' => 'associate',
        ])->assertForbidden();

        $this->signIn(Role::ManagingPartner, $this->firm);

        $this->postJson('/api/v1/users', [
            'name' => 'New Associate', 'email' => 'new@firm.ph', 'password' => 'password123', 'role' => 'associate',
        ])->assertCreated()->assertJsonPath('role', 'associate');
    }

    public function test_the_managing_partner_cannot_lock_themselves_out(): void
    {
        $me = $this->signIn(Role::ManagingPartner, $this->firm);

        $this->putJson("/api/v1/users/{$me->id}", ['is_active' => false])->assertStatus(422);
        $this->putJson("/api/v1/users/{$me->id}", ['role' => 'associate'])->assertStatus(422);
        $this->deleteJson("/api/v1/users/{$me->id}")->assertForbidden();
    }

    public function test_deleting_a_user_deactivates_rather_than_erases(): void
    {
        $this->signIn(Role::ManagingPartner, $this->firm);
        $associate = User::factory()->create(['firm_id' => $this->firm->id]);

        $this->deleteJson("/api/v1/users/{$associate->id}")->assertNoContent();

        $this->assertFalse($associate->fresh()->is_active);
    }

    public function test_administrative_staff_cannot_change_matter_status(): void
    {
        $matter = Matter::factory()->for(Client::factory()->for($this->firm))->create();
        $this->signIn(Role::Staff, $this->firm);

        $this->postJson("/api/v1/matters/{$matter->id}/status", ['status' => 'filed'])->assertForbidden();
    }

    public function test_associates_may_deposit_but_not_disburse_trust_funds(): void
    {
        $account = TrustAccount::factory()->for(Client::factory()->for($this->firm))->create();
        app(TrustLedgerService::class)->deposit($account, 100_000, 'Seed');
        $this->signIn(Role::Associate, $this->firm);

        $this->postJson("/api/v1/trust-accounts/{$account->id}/transactions", [
            'type' => 'deposit', 'amount_cents' => 5_000, 'description' => 'Additional deposit',
        ])->assertCreated();

        $this->postJson("/api/v1/trust-accounts/{$account->id}/transactions", [
            'type' => 'disbursement', 'amount_cents' => 5_000, 'description' => 'Filing fee',
        ])->assertForbidden();
    }

    public function test_paralegals_cannot_see_invoices_or_analytics(): void
    {
        $this->signIn(Role::Paralegal, $this->firm);

        $this->getJson('/api/v1/invoices')->assertForbidden();
        $this->getJson('/api/v1/analytics/dashboard')->assertForbidden();
        $this->getJson('/api/v1/audit-logs')->assertForbidden();
    }

    public function test_forbidden_responses_use_the_error_envelope(): void
    {
        $this->signIn(Role::Paralegal, $this->firm);

        $this->getJson('/api/v1/analytics/dashboard')
            ->assertForbidden()
            ->assertJsonPath('status', 'error')
            ->assertJsonStructure(['status', 'message']);
    }
}
