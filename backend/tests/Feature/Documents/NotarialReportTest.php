<?php

namespace Tests\Feature\Documents;

use App\Domain\Documents\Models\NotarialEntry;
use App\Domain\Documents\Notifications\NotarialReportDue;
use App\Domain\Documents\Services\NotarialReports;
use App\Domain\Matters\Models\Firm;
use App\Enums\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class NotarialReportTest extends TestCase
{
    use RefreshDatabase;

    private Firm $firm;

    private User $notary;

    protected function setUp(): void
    {
        parent::setUp();
        $this->firm = Firm::factory()->create();
        $this->notary = $this->signIn(Role::Partner, $this->firm, ['name' => 'Atty. Rosa Lim', 'notarial_commission_number' => 'NP-123-2026', 'notarial_commission_place' => 'Makati City']);
        foreach ([['2026-09-05 10:00', 'Deed of Sale'], ['2026-09-20 14:00', 'Affidavit of Loss'], ['2026-10-02 09:00', 'Special Power of Attorney']] as $i => [$at, $title]) {
            NotarialEntry::create(['firm_id' => $this->firm->id, 'notary_id' => $this->notary->id, 'doc_number' => $i + 1, 'page_number' => 1, 'book_number' => 1, 'series_year' => 2026, 'act_type' => 'acknowledgment', 'document_title' => $title, 'principal_name' => 'Juan Cruz', 'competent_evidence' => 'Passport P1234567', 'fee_cents' => 50_000, 'notarized_at' => CarbonImmutable::parse($at, 'Asia/Manila')]);
        }
        $this->travelTo(CarbonImmutable::parse('2026-10-06 09:00', 'Asia/Manila'));
    }

    public function test_the_months_report_downloads_and_is_marked_submitted(): void
    {
        $months = collect($this->getJson('/api/v1/notarial-reports')->assertOk()->json('months'))->keyBy('month');
        $this->assertSame(2, $months['2026-09']['entries']);
        $this->assertNull($months['2026-09']['submitted_at']);

        $this->get('/api/v1/notarial-reports/2026-09/pdf')->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->get('/api/v1/notarial-reports/2026-08/pdf')->assertOk();   // a nil report
        $this->get('/api/v1/notarial-reports/2026-10/pdf')->assertStatus(422);

        $this->postJson('/api/v1/notarial-reports/2026-09/submitted', ['notes' => 'Received by the OCC Makati on Oct 6'])->assertOk();
        $this->assertNotNull(collect($this->getJson('/api/v1/notarial-reports')->json('months'))->firstWhere('month', '2026-09')['submitted_at']);
    }

    public function test_notaries_are_reminded_until_it_is_submitted(): void
    {
        Notification::fake();
        $reports = app(NotarialReports::class);

        $this->assertSame(0, $reports->remind(CarbonImmutable::parse('2026-10-03')));
        $this->assertSame(1, $reports->remind(CarbonImmutable::parse('2026-10-05')));
        $this->assertSame(0, $reports->remind(CarbonImmutable::parse('2026-10-05')), 'Once a day.');
        Notification::assertSentTo($this->notary, NotarialReportDue::class, fn ($n) => $n->report->entries === 2);

        $this->postJson('/api/v1/notarial-reports/2026-09/submitted')->assertOk();
        $this->assertSame(0, $reports->remind(CarbonImmutable::parse('2026-10-09')));
    }

    public function test_only_a_managing_partner_sees_another_notarys_report(): void
    {
        $other = $this->signIn(Role::Associate, $this->firm);
        $this->getJson("/api/v1/notarial-reports?notary_id={$this->notary->id}")->assertForbidden();
        $this->getJson('/api/v1/notarial-reports')->assertOk()->assertJsonPath('notary.id', $other->id);

        $this->signIn(Role::ManagingPartner, $this->firm);
        $this->getJson("/api/v1/notarial-reports?notary_id={$this->notary->id}")->assertOk()->assertJsonPath('notary.name', 'Atty. Rosa Lim');
    }
}
