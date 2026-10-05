<?php

namespace Tests\Feature\Intake;

use App\Domain\Intake\Models\IntakeRequest;
use App\Domain\Intake\Notifications\ConsultationScheduled;
use App\Domain\Intake\Notifications\IntakeReceived;
use App\Domain\Matters\Models\Firm;
use App\Domain\Prescription\MatterPrescription;
use App\Enums\Role;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class IntakePrescriptionAndLanguageTest extends TestCase
{
    use RefreshDatabase;

    private Firm $firm;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-05 09:00:00');
        Notification::fake();
        $this->firm = Firm::factory()->create(['name' => 'Santos Law', 'slug' => 'santos-law', 'intake_enabled' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function form(array $overrides = []): array
    {
        return [
            'name' => 'Maria Clara Santos',
            'email' => 'maria@example.com',
            'client_type' => 'individual',
            'case_type' => 'Labor',
            'description' => 'I was dismissed without notice after eight years of work and was not paid my final salary.',
            'opposing_parties' => [],
            'preferred_times' => [],
            'consent' => true,
            ...$overrides,
        ];
    }

    public function test_the_form_works_in_filipino_and_so_do_the_applicants_emails(): void
    {
        $page = $this->getJson('/api/public/intake/santos-law', ['X-Locale' => 'fil'])->assertOk();
        $this->assertContains(['value' => 'Labor', 'label' => 'Paggawa (Labor)'], $page->json('case_types'));
        $this->assertStringContainsString('Kinokolekta at ginagamit ng Santos Law', $page->json('privacy_notice'));

        $this->postJson('/api/public/intake/santos-law', $this->form(['consent' => false, 'incident_on' => '2027-01-01']), ['X-Locale' => 'fil'])
            ->assertStatus(422)
            ->assertJsonPath('errors.consent.0', 'Pakisang-ayunan ang pagproseso ng iyong impormasyon upang makatugon kami.')
            ->assertJsonPath('errors.incident_on.0', 'Hindi maaaring sa hinaharap ang petsa.');

        $this->postJson('/api/public/intake/santos-law', $this->form(), ['X-Locale' => 'fil'])
            ->assertCreated()->assertJsonPath('message', 'Salamat. Natanggap namin ang iyong kahilingan; padadalhan ka namin ng email para kumpirmahin ang iskedyul ng konsultasyon.');
        $this->assertSame('fil', IntakeRequest::withoutGlobalScopes()->firstOrFail()->locale);

        Notification::assertSentTo(new AnonymousNotifiable, IntakeReceived::class, function ($n, $channels, $notifiable, $locale) {
            app()->setLocale('fil');
            $mail = $n->toMail($notifiable);
            app()->setLocale('en');

            return $locale === 'fil' && str_contains($mail->subject, 'Natanggap namin') && str_contains(implode(' ', $mail->introLines), 'usaping Paggawa (Labor)');
        });
    }

    public function test_an_english_form_stays_english(): void
    {
        $this->getJson('/api/public/intake/santos-law')->assertJsonPath('case_types.0', ['value' => 'Civil', 'label' => 'Civil']);
        $this->postJson('/api/public/intake/santos-law', $this->form())->assertCreated();
        Notification::assertSentTo(new AnonymousNotifiable, IntakeReceived::class, fn ($n, $c, $to, $locale) => $locale === 'en');
    }

    public function test_a_lawyer_screens_prescription_before_taking_the_case_and_it_carries_to_the_matter(): void
    {
        // Dismissed on Oct 20, 2022: illegal dismissal prescribes 4 years later, Oct 20, 2026.
        $this->postJson('/api/public/intake/santos-law', $this->form(['incident_on' => '2022-10-20']))->assertCreated();
        $id = IntakeRequest::withoutGlobalScopes()->firstOrFail()->id;

        $partner = $this->signIn(Role::Partner, $this->firm);
        $this->getJson("/api/v1/intake-requests/{$id}")->assertJsonPath('incident_on', '2022-10-20')->assertJsonPath('prescription', null);

        $this->putJson("/api/v1/intake-requests/{$id}/prescription", ['period_key' => 'illegal_dismissal'])
            ->assertOk()
            ->assertJsonPath('prescription.label', 'Illegal dismissal')
            ->assertJsonPath('prescription.last_day', '2026-10-20')
            ->assertJsonPath('prescription.days_left', 15)
            ->assertJsonPath('prescription.state', 'urgent');
        $this->getJson('/api/v1/intake-requests')->assertJsonPath('data.0.prescription.state', 'urgent');

        // Money claims (3 years) already prescribed: the lawyer sees it at once.
        $this->putJson("/api/v1/intake-requests/{$id}/prescription", ['period_key' => 'money_claims'])->assertJsonPath('prescription.state', 'prescribed');
        $this->putJson("/api/v1/intake-requests/{$id}/prescription", ['period_key' => 'illegal_dismissal', 'incident_on' => '2022-11-03'])
            ->assertJsonPath('prescription.last_day', '2026-11-03');

        $matterId = $this->postJson("/api/v1/intake-requests/{$id}/accept")->assertOk()->json('matter_id');
        $tracked = MatterPrescription::where('matter_id', $matterId)->firstOrFail();
        $this->assertSame('illegal_dismissal', $tracked->period_key);
        $this->assertSame('2022-11-03', $tracked->accrued_on->toDateString());
        $this->assertSame('2026-11-03', $tracked->last_day->toDateString());
        $this->assertStringContainsString('online intake', $tracked->notes);
        $this->assertSame($partner->id, $tracked->created_by);
        $this->getJson("/api/v1/intake-requests/{$id}")->assertJsonPath('prescription', null); // the matter tracks it now
    }

    public function test_a_prospect_tracked_from_intake_carries_it_on_engagement(): void
    {
        $this->postJson('/api/public/intake/santos-law', $this->form(['incident_on' => '2024-01-15', 'case_type' => 'Civil']))->assertCreated();
        $id = IntakeRequest::withoutGlobalScopes()->firstOrFail()->id;
        $this->signIn(Role::Partner, $this->firm);
        $this->putJson("/api/v1/intake-requests/{$id}/prescription", ['period_key' => 'quasi_delict'])->assertOk();

        $prospect = $this->postJson("/api/v1/intake-requests/{$id}/prospect")->assertSuccessful()->json('id');
        $matterId = $this->postJson("/api/v1/prospects/{$prospect}/convert")->assertSuccessful()->json('matter_id');

        $this->assertSame('2028-01-15', MatterPrescription::where('matter_id', $matterId)->firstOrFail()->last_day->toDateString());
    }

    public function test_without_a_date_or_period_nothing_is_tracked(): void
    {
        $this->postJson('/api/public/intake/santos-law', $this->form())->assertCreated();
        $id = IntakeRequest::withoutGlobalScopes()->firstOrFail()->id;
        $this->signIn(Role::Partner, $this->firm);
        $this->putJson("/api/v1/intake-requests/{$id}/prescription", ['period_key' => 'written_contract'])->assertJsonPath('prescription', null);

        $matterId = $this->postJson("/api/v1/intake-requests/{$id}/accept")->assertOk()->json('matter_id');
        $this->assertSame(0, MatterPrescription::where('matter_id', $matterId)->count());
    }

    public function test_the_consultation_email_follows_the_applicants_language(): void
    {
        $this->postJson('/api/public/intake/santos-law', $this->form(), ['X-Locale' => 'fil'])->assertCreated();
        $id = IntakeRequest::withoutGlobalScopes()->firstOrFail()->id;
        $lawyer = $this->signIn(Role::Partner, $this->firm);

        $this->postJson("/api/v1/intake-requests/{$id}/schedule", ['consultation_at' => '2026-10-12 14:00', 'assigned_lawyer_id' => $lawyer->id])->assertOk();
        Notification::assertSentTo(new AnonymousNotifiable, ConsultationScheduled::class, function ($n, $c, $to, $locale) {
            app()->setLocale('fil');
            $line = $n->toMail($to)->introLines[0];
            app()->setLocale('en');

            return $locale === 'fil' && str_contains($line, 'Lunes, Oktubre 12, 2026, 2:00 PM') && str_contains($line, 'oras sa Pilipinas');
        });
    }
}
