<?php

namespace Tests\Feature\Billing;

use App\Domain\Matters\Models\Client;
use App\Domain\Matters\Models\Firm;
use App\Domain\Matters\Models\Matter;
use App\Enums\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FilingFeeTest extends TestCase
{
    use RefreshDatabase;

    private Firm $firm;

    private Matter $matter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->firm = Firm::factory()->create();
        $this->signIn(Role::ManagingPartner, $this->firm);
        $this->matter = Matter::factory()->for(Client::factory()->for($this->firm))->create();
    }

    private function table(array $extra = []): array
    {
        return [
            'court' => 'RTC: money claims',
            'brackets' => [['under_cents' => 15_000_000, 'fee_cents' => 150_000], ['under_cents' => 10_000_000, 'fee_cents' => 100_000]],
            'excess' => ['from_cents' => 15_000_000, 'base_cents' => 150_000, 'per_thousand_cents' => 1_000],
            'extras' => [['label' => 'Legal Research Fund', 'percent_bps' => 100, 'minimum_cents' => 1_000], ['label' => 'Mediation fee', 'fixed_cents' => 50_000]],
            'confirm' => true,
            ...$extra,
        ];
    }

    public function test_no_estimate_until_the_table_is_reviewed(): void
    {
        $this->getJson('/api/v1/filing-fees')->assertOk()->assertJsonPath('confirmed', false);
        $this->postJson("/api/v1/matters/{$this->matter->id}/filing-fees/estimate", ['claim_cents' => 5_000_000])->assertJsonValidationErrors('schedule');

        $this->putJson('/api/v1/filing-fees', $this->table(['confirm' => false]))->assertJsonValidationErrors('confirm');
        $this->putJson('/api/v1/filing-fees', $this->table())->assertOk()->assertJsonPath('confirmed', true)->assertJsonPath('schedule.brackets.0.under_cents', 10_000_000);
    }

    public function test_the_estimate_follows_the_brackets_the_excess_and_the_extras(): void
    {
        $this->putJson('/api/v1/filing-fees', $this->table())->assertOk();
        $estimate = fn (int $claim) => $this->postJson("/api/v1/matters/{$this->matter->id}/filing-fees/estimate", ['claim_cents' => $claim])->assertOk()->json();

        // ₱50,000 claim: ₱1,000 fee; LRF 1% is ₱10 (the minimum); mediation ₱500.
        $this->assertSame([['label' => 'Filing (docket) fee', 'amount_cents' => 100_000], ['label' => 'Legal Research Fund', 'amount_cents' => 1_000], ['label' => 'Mediation fee', 'amount_cents' => 50_000]], $estimate(5_000_000)['lines']);
        $this->assertSame(151_000, $estimate(5_000_000)['total_cents']);

        // ₱160,500 claim: ₱1,500 + ₱10 for each ₱1,000 or part above ₱150,000 (11 of them) = ₱1,610.
        $this->assertSame(161_000, $estimate(16_050_000)['lines'][0]['amount_cents']);
    }

    public function test_only_a_managing_partner_confirms_the_table(): void
    {
        $this->signIn(Role::Partner, $this->firm);
        $this->putJson('/api/v1/filing-fees', $this->table())->assertForbidden();
    }
}
