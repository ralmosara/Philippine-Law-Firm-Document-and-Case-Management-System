<?php

namespace Tests\Feature\Business;

use App\Domain\Business\Models\Prospect;
use App\Domain\Business\Notifications\ProspectFollowUp;
use App\Domain\Business\Pipeline;
use App\Domain\Compliance\Enums\ConflictCheckStatus;
use App\Domain\Compliance\Models\ConflictCheck;
use App\Domain\Intake\Models\IntakeRequest;
use App\Domain\Matters\Enums\PartyRole;
use App\Domain\Matters\Models\Client;
use App\Domain\Matters\Models\Firm;
use App\Domain\Matters\Models\Matter;
use App\Enums\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PipelineTest extends TestCase
{
    use RefreshDatabase;

    private Firm $firm;

    private User $lawyer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-09-29 09:00', 'Asia/Manila'));
        $this->firm = Firm::factory()->create();
        $this->lawyer = $this->signIn(Role::Partner, $this->firm);
    }

    private function prospect(array $data = []): array
    {
        return $this->postJson('/api/v1/prospects', [
            'name' => 'Luzon Shipping Corp.', 'client_type' => 'corporate', 'email' => 'legal@luzonship.ph', 'source' => 'referral',
            'referred_by' => 'Atty. Cruz', 'case_type' => 'Corporate', 'estimated_value_cents' => 25_000_000, 'opposing_parties' => ['Visayas Freight Inc.'], ...$data,
        ])->assertCreated()->json();
    }

    public function test_a_prospect_moves_through_the_pipeline_and_becomes_a_client_and_matter(): void
    {
        $p = $this->prospect();
        $this->assertSame('lead', $p['stage']);

        $this->postJson("/api/v1/prospects/{$p['id']}/stage", ['stage' => 'consultation', 'note' => 'Met at their office'])->assertOk();
        $this->postJson("/api/v1/prospects/{$p['id']}/stage", ['stage' => 'proposal'])->assertOk()->assertJsonPath('proposal_sent_on', '2026-09-29');
        $this->postJson("/api/v1/prospects/{$p['id']}/stage", ['stage' => 'won'])->assertStatus(422); // only by converting
        $this->postJson("/api/v1/prospects/{$p['id']}/notes", ['type' => 'call', 'body' => 'Asked for a retainer quote'])->assertCreated();

        $res = $this->postJson("/api/v1/prospects/{$p['id']}/convert", ['title' => 'Luzon Shipping: retainer'])->assertCreated();

        $matter = Matter::findOrFail($res->json('matter_id'));
        $this->assertSame('Luzon Shipping: retainer', $matter->title);
        $this->assertSame('corporate', Client::findOrFail($matter->client_id)->type);
        $this->assertSame('Visayas Freight Inc.', $matter->parties()->where('role', PartyRole::AdverseParty)->value('name'));

        $detail = $this->getJson("/api/v1/prospects/{$p['id']}")->assertOk()
            ->assertJsonPath('stage', 'won')
            ->assertJsonPath('engagement_signed_on', '2026-09-29')
            ->assertJsonPath('matter.id', $matter->id);
        $this->assertSame(['won', null, 'proposal', 'consultation', 'lead'], array_column($detail->json('events'), 'to_stage'));
        $this->postJson("/api/v1/prospects/{$p['id']}/convert")->assertStatus(422); // closed
    }

    public function test_conversion_waits_for_flagged_conflicts(): void
    {
        $existing = Client::factory()->for($this->firm)->create(['name' => 'Visayas Freight Inc.']);
        $p = $this->prospect();
        $flagged = ConflictCheck::whereIn('id', Prospect::find($p['id'])->conflict_check_ids)->where('status', ConflictCheckStatus::Flagged)->sole();
        $this->assertSame('Visayas Freight Inc.', $flagged->search_term);

        $this->postJson("/api/v1/prospects/{$p['id']}/convert")->assertStatus(422)->assertJsonValidationErrors('conflicts');

        $flagged->forceFill(['status' => ConflictCheckStatus::Waived, 'resolution_notes' => 'Different company', 'resolved_by' => $this->lawyer->id, 'resolved_at' => now()])->save();
        $this->postJson("/api/v1/prospects/{$p['id']}/convert")->assertCreated();
        $this->assertNotSame($existing->id, Prospect::find($p['id'])->client_id);

        // New opposing parties are checked when added.
        $q = $this->prospect(['name' => 'Mindanao Agri Co.', 'email' => null, 'opposing_parties' => []]);
        $this->putJson("/api/v1/prospects/{$q['id']}", ['opposing_parties' => ['Pedro Santos']])->assertOk();
        $this->assertContains('Pedro Santos', Prospect::find($q['id'])->conflictChecks()->pluck('search_term')->all());
    }

    public function test_the_report_shows_conversion_by_source_and_the_open_pipeline(): void
    {
        $won = $this->prospect();
        $this->postJson("/api/v1/prospects/{$won['id']}/convert")->assertCreated();
        $lost = $this->prospect(['name' => 'Ana Reyes', 'client_type' => 'individual', 'email' => 'ana@example.com', 'source' => 'website', 'opposing_parties' => [], 'estimated_value_cents' => 5_000_000]);
        $this->postJson("/api/v1/prospects/{$lost['id']}/stage", ['stage' => 'lost'])->assertStatus(422)->assertJsonValidationErrors('lost_reason');
        $this->postJson("/api/v1/prospects/{$lost['id']}/stage", ['stage' => 'lost', 'lost_reason' => 'Fees too high'])->assertOk();
        $open = $this->prospect(['name' => 'Ben Tan', 'client_type' => 'individual', 'email' => 'ben@example.com', 'source' => 'referral', 'opposing_parties' => [], 'estimated_value_cents' => 8_000_000]);
        $this->postJson("/api/v1/prospects/{$open['id']}/stage", ['stage' => 'proposal'])->assertOk();

        $report = $this->getJson('/api/v1/prospects/report')->assertOk();
        $this->assertSame(3, $report->json('overall.total'));
        $this->assertEquals(50, $report->json('overall.win_rate'));
        $referral = collect($report->json('by_source'))->firstWhere('source', 'referral');
        $this->assertSame(2, $referral['total']);
        $this->assertSame(1, $referral['won']);
        $this->assertEquals(100, $referral['win_rate']);
        $this->assertSame(25_000_000, $referral['won_value_cents']);
        $this->assertSame('fees too high', $report->json('lost_reasons.0.reason'));
        $this->assertSame(8_000_000, collect($report->json('pipeline'))->firstWhere('stage', 'proposal')['value_cents']);

        $this->assertCount(1, $this->getJson('/api/v1/prospects')->json('data'));
        $this->assertCount(2, $this->getJson('/api/v1/prospects?closed=1')->json('data'));

        $this->signIn(Role::Associate, $this->firm);
        $this->getJson('/api/v1/prospects/report')->assertForbidden();
        $this->signIn(Role::ManagingPartner, Firm::factory()->create());
        $this->getJson("/api/v1/prospects/{$open['id']}")->assertNotFound();
    }

    public function test_owners_are_reminded_of_follow_ups_and_intake_requests_join_the_pipeline(): void
    {
        Notification::fake();
        $p = $this->prospect(['next_step' => 'Send the retainer proposal', 'next_step_on' => '2026-10-01']);
        $pipeline = app(Pipeline::class);

        $this->assertSame(0, $pipeline->sendFollowUps(CarbonImmutable::parse('2026-09-30')));
        $this->assertSame(1, $pipeline->sendFollowUps(CarbonImmutable::parse('2026-10-01')));
        $this->assertSame(0, $pipeline->sendFollowUps(CarbonImmutable::parse('2026-10-02'))); // once per date
        Notification::assertSentTo($this->lawyer, ProspectFollowUp::class);
        $this->putJson("/api/v1/prospects/{$p['id']}", ['next_step_on' => '2026-10-05'])->assertOk();
        $this->assertSame(1, $pipeline->sendFollowUps(CarbonImmutable::parse('2026-10-05')));

        $intake = IntakeRequest::create([
            'firm_id' => $this->firm->id, 'name' => 'Carla Mendoza', 'email' => 'carla@example.com', 'client_type' => 'individual',
            'case_type' => 'Family', 'description' => 'Annulment', 'opposing_parties' => ['Mark Mendoza'], 'preferred_times' => [], 'consent_at' => now(), 'conflict_check_ids' => [], 'conflict_status' => 'clear',
        ]);
        $from = $this->postJson("/api/v1/intake-requests/{$intake->id}/prospect")->assertCreated()->assertJsonPath('source', 'website')->assertJsonPath('intake_request_id', $intake->id);
        $this->postJson("/api/v1/intake-requests/{$intake->id}/prospect")->assertOk()->assertJsonPath('id', $from->json('id'));
    }
}
