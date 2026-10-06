<?php

namespace Tests\Feature\Business;

use App\Domain\Matters\Models\Firm;
use App\Enums\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EngagementClausesTest extends TestCase
{
    use RefreshDatabase;

    private Firm $firm;

    protected function setUp(): void
    {
        parent::setUp();
        $this->firm = Firm::factory()->create(['dpo_email' => null]);
        $this->signIn(Role::ManagingPartner, $this->firm);
    }

    private function draft(): string
    {
        $prospect = $this->postJson('/api/v1/prospects', ['name' => 'Ana Lim', 'client_type' => 'individual', 'email' => 'ana@example.ph', 'source' => 'referral', 'opposing_parties' => []])->json('id');

        return $this->postJson("/api/v1/prospects/{$prospect}/engagement-letters", ['fee_arrangement' => 'flat', 'fixed_fee_cents' => 100_000, 'scope' => 'Advice on a lease.'])->assertCreated()->json('content');
    }

    public function test_new_letters_use_the_firms_wording(): void
    {
        $standard = $this->draft();
        $this->assertStringContainsString('3. Expenses', $standard);
        $this->assertStringNotContainsString('{dpo_email}', $standard, 'Without a DPO on file the sentence naming one is left out.');
        $this->assertStringNotContainsString('Data Protection Officer can be reached', $standard);

        $this->putJson('/api/v1/engagement-clauses', ['clauses' => ['billing' => 'Our statements are due within 15 days.', 'privacy' => 'Ask our DPO at {dpo_email} anything.']])
            ->assertOk()->assertJsonPath('clauses.1.customised', true)->assertJsonPath('clauses.1.text', 'Our statements are due within 15 days.');
        $this->firm->update(['dpo_email' => 'dpo@firm.ph']);

        $custom = $this->draft();
        $this->assertStringContainsString("4. Billing\n\nOur statements are due within 15 days.", $custom);
        $this->assertStringContainsString('Ask our DPO at dpo@firm.ph anything.', $custom);

        // A blank clause goes back to the standard.
        $this->putJson('/api/v1/engagement-clauses', ['clauses' => ['billing' => '']])->assertOk()->assertJsonPath('clauses.1.customised', false);
    }

    public function test_only_a_managing_partner_edits_them(): void
    {
        $this->signIn(Role::Partner, $this->firm);
        $this->getJson('/api/v1/engagement-clauses')->assertOk();
        $this->putJson('/api/v1/engagement-clauses', ['clauses' => ['billing' => 'x']])->assertForbidden();
    }
}
