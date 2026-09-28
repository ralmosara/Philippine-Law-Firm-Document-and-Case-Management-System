<?php

namespace Tests\Feature\Corporate;

use App\Domain\Corporate\CorporateSecretarial;
use App\Domain\Corporate\Models\CorporateObligation;
use App\Domain\Corporate\Models\CorporateProfile;
use App\Domain\Corporate\Notifications\CorporateObligationDue;
use App\Domain\Documents\Models\DocumentTemplate;
use App\Domain\Documents\Services\DocumentMerger;
use App\Domain\Matters\Models\Client;
use App\Domain\Matters\Models\Firm;
use App\Domain\Matters\Models\Matter;
use App\Enums\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class CorporateSecretarialTest extends TestCase
{
    use RefreshDatabase;

    private Firm $firm;

    private User $lawyer;

    private Client $acme;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-03-02 09:00', 'Asia/Manila'));
        $this->firm = Firm::factory()->create();
        $this->lawyer = $this->signIn(Role::Associate, $this->firm);
        $this->acme = Client::factory()->for($this->firm)->create(['name' => 'Acme Trading Corp.', 'tin' => '222-333-444-000']);
    }

    private function profile(array $overrides = []): array
    {
        return $this->putJson("/api/v1/clients/{$this->acme->id}/corporate", [
            'sec_registration_no' => 'CS201912345',
            'fiscal_year_end' => '12-31',
            'annual_meeting_date' => '04-15',
            'principal_office' => '8th Floor, Ayala Tower, Makati City',
            'corporate_secretary' => 'Atty. Maria Cruz',
            'responsible_lawyer_id' => $this->lawyer->id,
            ...$overrides,
        ])->assertSuccessful()->json();
    }

    public function test_a_profile_generates_the_years_sec_and_bir_obligations(): void
    {
        $this->profile();

        $obligations = collect($this->getJson("/api/v1/clients/{$this->acme->id}/corporate")->assertOk()->json('obligations'))
            ->where('year', 2026)->keyBy('kind');

        $this->assertSame('2026-04-15', $obligations['annual_meeting']['due_on']);
        $this->assertSame('2026-05-15', $obligations['gis']['due_on']);              // 30 days after the meeting
        $this->assertSame('2027-04-30', $obligations['afs']['due_on']);              // 120 days after Dec 31, 2026
        $this->assertSame('2027-04-15', $obligations['annual_itr']['due_on']);       // 15th day of the 4th month

        // A June 30 fiscal year: 120 days -> Oct 28; the ITR on Oct 15.
        $fy = app(CorporateSecretarial::class)->obligationsFor(new CorporateProfile(['fiscal_year_end' => '06-30']), 2026);
        $this->assertSame('2026-10-28', collect($fy)->firstWhere('kind', 'afs')['due_on']);
        $this->assertSame('2026-10-15', collect($fy)->firstWhere('kind', 'annual_itr')['due_on']);
        // Without a meeting date there is no meeting or GIS to generate.
        $this->assertNull(collect($fy)->firstWhere('kind', 'gis'));

        // Dates on weekends move to the next working day: May 15, 2027 is a Saturday.
        $next = collect(app(CorporateSecretarial::class)->obligationsFor(new CorporateProfile(['annual_meeting_date' => '04-15']), 2027));
        $this->assertSame('2027-05-17', $next->firstWhere('kind', 'gis')['due_on']);
    }

    public function test_a_company_added_mid_year_is_not_shown_overdue_for_what_came_before(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-28 09:00', 'Asia/Manila'));
        $this->profile();

        $kinds = CorporateObligation::where('year', 2026)->pluck('kind')->all();
        $this->assertNotContains('annual_meeting', $kinds); // April 15, before it was tracked
        $this->assertNotContains('gis', $kinds);
        $this->assertContains('afs', $kinds);               // April 2027, still ahead
        $this->assertSame(4, CorporateObligation::where('year', 2027)->count());
    }

    public function test_obligations_are_marked_done_and_a_new_schedule_regenerates_the_pending_ones(): void
    {
        $this->profile();
        $gis = CorporateObligation::where('kind', 'gis')->where('year', 2026)->first();

        $this->patchJson("/api/v1/corporate-obligations/{$gis->id}", ['status' => 'done'])->assertStatus(422)->assertJsonValidationErrors('done_on');
        $this->patchJson("/api/v1/corporate-obligations/{$gis->id}", ['status' => 'done', 'done_on' => '2026-03-01', 'reference' => 'SEC-eFAST 0001'])
            ->assertOk()->assertJsonPath('status', 'done')->assertJsonPath('completed_by', $this->lawyer->name);

        // The by-laws move the meeting: pending ones follow, the filed GIS stays as recorded.
        $this->profile(['annual_meeting_date' => '05-20']);
        $this->assertSame('2026-05-20', CorporateObligation::where('kind', 'annual_meeting')->where('year', 2026)->sole()->due_on->toDateString());
        $this->assertSame('done', CorporateObligation::where('kind', 'gis')->where('year', 2026)->sole()->status);

        // Custom obligations can be added and removed; standard ones cannot be deleted.
        $custom = $this->postJson("/api/v1/clients/{$this->acme->id}/corporate/obligations", ['title' => 'Renew mayor\'s permit', 'due_on' => '2027-01-20'])
            ->assertCreated()->assertJsonPath('year', 2027)->json('id');
        $this->deleteJson("/api/v1/corporate-obligations/{$custom}")->assertNoContent();
        $this->deleteJson("/api/v1/corporate-obligations/{$gis->id}")->assertStatus(422);

        $companies = $this->getJson('/api/v1/corporate?year=2026')->assertOk()->json('companies');
        $this->assertSame('Acme Trading Corp.', $companies[0]['profile']['client_name']);
        $this->assertCount(4, $companies[0]['obligations']);
    }

    public function test_the_responsible_lawyer_is_reminded(): void
    {
        $this->profile();
        Notification::fake();
        $secretarial = app(CorporateSecretarial::class);

        // Mar 20: the April 15 meeting is 26 days away.
        $this->assertSame(1, $secretarial->sendReminders(CarbonImmutable::parse('2026-03-20')));
        $this->assertSame(0, $secretarial->sendReminders(CarbonImmutable::parse('2026-03-21'))); // once per stage
        $this->assertSame(1, $secretarial->sendReminders(CarbonImmutable::parse('2026-04-09'))); // a week before
        Notification::assertSentTo($this->lawyer, CorporateObligationDue::class, fn ($n) => $n->obligation->kind === 'annual_meeting' && $n->stage === 'week');

        // Done obligations are not chased.
        CorporateObligation::where('kind', 'annual_meeting')->update(['status' => 'done', 'done_on' => '2026-04-15']);
        Notification::fake();
        $secretarial->sendReminders(CarbonImmutable::parse('2026-04-16'));
        Notification::assertNotSentTo($this->lawyer, CorporateObligationDue::class, fn ($n) => $n->obligation->kind === 'annual_meeting');
    }

    public function test_standard_templates_install_once_and_fill_from_the_profile(): void
    {
        $this->profile();

        $this->postJson('/api/v1/corporate/templates')->assertOk()->assertJsonPath('added', 4);
        $this->postJson('/api/v1/corporate/templates')->assertOk()->assertJsonPath('added', 0);

        $certificate = DocumentTemplate::where('name', "Secretary's Certificate")->sole();
        $matter = Matter::factory()->for($this->acme)->create();
        $text = app(DocumentMerger::class)->merge($certificate->body, app(DocumentMerger::class)->dataFor($matter));

        $this->assertStringContainsString('I, Atty. Maria Cruz,', $text);
        $this->assertStringContainsString('SEC Registration No. CS201912345', $text);
        $this->assertStringContainsString('{{ resolution_text }}', $text); // left for the lawyer to fill
        $this->assertSame('December 31, 2025', app(DocumentMerger::class)->dataFor($matter)['fiscal_year_end_text']);
    }

    public function test_staff_cannot_edit_and_other_firms_cannot_see(): void
    {
        $this->profile();
        $id = CorporateObligation::value('id');

        $this->signIn(Role::Staff, $this->firm);
        $this->getJson('/api/v1/corporate')->assertOk();
        $this->putJson("/api/v1/clients/{$this->acme->id}/corporate", ['fiscal_year_end' => '12-31'])->assertForbidden();

        $this->signIn(Role::ManagingPartner, Firm::factory()->create());
        $this->assertSame([], $this->getJson('/api/v1/corporate')->json('companies'));
        $this->patchJson("/api/v1/corporate-obligations/{$id}", ['status' => 'not_applicable'])->assertNotFound();
        $this->getJson("/api/v1/clients/{$this->acme->id}/corporate")->assertNotFound();
    }
}
