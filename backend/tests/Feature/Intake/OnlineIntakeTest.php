<?php

namespace Tests\Feature\Intake;

use App\Domain\Compliance\Models\ConflictCheck;
use App\Domain\Intake\Models\IntakeRequest;
use App\Domain\Intake\Notifications\ConsultationScheduled;
use App\Domain\Intake\Notifications\IntakeDeclined;
use App\Domain\Intake\Notifications\IntakeReceived;
use App\Domain\Intake\Notifications\NewIntakeRequest;
use App\Domain\Matters\Models\Client;
use App\Domain\Matters\Models\Firm;
use App\Domain\Matters\Models\Matter;
use App\Domain\Matters\Models\MatterParty;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class OnlineIntakeTest extends TestCase
{
    use RefreshDatabase;

    private Firm $firm;

    private User $partner;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->firm = Firm::factory()->create(['name' => 'Santos Law', 'slug' => 'santos-law', 'intake_enabled' => true]);
        $this->partner = User::factory()->role(Role::Partner)->create(['firm_id' => $this->firm->id]);
    }

    public function test_the_public_form_is_available_only_when_enabled(): void
    {
        $this->getJson('/api/public/intake/santos-law')->assertOk()->assertJsonPath('firm.name', 'Santos Law')->assertJsonFragment(['Labor']);

        $this->firm->update(['intake_enabled' => false]);
        $this->getJson('/api/public/intake/santos-law')->assertNotFound();
        $this->getJson('/api/public/intake/nobody')->assertNotFound();
    }

    public function test_a_request_is_conflict_checked_and_acknowledged(): void
    {
        // Existing client of the firm, who is the opposing party in the new request.
        $existing = Client::factory()->for($this->firm)->create(['name' => 'Reyes Holdings Inc.']);
        Matter::factory()->for($existing)->create();

        $this->postJson('/api/public/intake/santos-law', $this->form(['opposing_parties' => ['Reyes Holdings Inc.']]))
            ->assertCreated();

        $request = IntakeRequest::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('flagged', $request->conflict_status);
        $this->assertCount(2, $request->conflict_check_ids);
        $this->assertNull(ConflictCheck::withoutGlobalScopes()->first()->requested_by);

        Notification::assertSentTo(new AnonymousNotifiable, IntakeReceived::class, fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === 'maria@example.com');
        Notification::assertSentTo($this->partner, NewIntakeRequest::class);
    }

    public function test_bots_and_bad_input_are_filtered(): void
    {
        $this->postJson('/api/public/intake/santos-law', $this->form(['website' => 'http://spam.example']))->assertCreated();
        $this->assertSame(0, IntakeRequest::withoutGlobalScopes()->count());

        $this->postJson('/api/public/intake/santos-law', $this->form(['consent' => false, 'case_type' => 'Piracy']))
            ->assertStatus(422)->assertJsonValidationErrors(['consent', 'case_type']);
    }

    public function test_the_form_is_rate_limited_per_address(): void
    {
        foreach (range(1, 5) as $_) {
            $this->postJson('/api/public/intake/santos-law', $this->form())->assertCreated();
        }
        $this->postJson('/api/public/intake/santos-law', $this->form())->assertStatus(429);
    }

    public function test_the_firm_schedules_and_accepts_a_clear_request_into_a_client_and_matter(): void
    {
        $this->postJson('/api/public/intake/santos-law', $this->form(['opposing_parties' => ['Pedro Penduko']]));
        $id = IntakeRequest::withoutGlobalScopes()->value('id');

        $this->actingAs($this->partner);
        $this->getJson('/api/v1/intake-requests')->assertJsonPath('open_count', 1)->assertJsonPath('data.0.conflict_status', 'clear');

        $this->postJson("/api/v1/intake-requests/{$id}/schedule", ['consultation_at' => now()->addDays(3)->setTime(14, 0)->toDateTimeString(), 'assigned_lawyer_id' => $this->partner->id])
            ->assertOk()->assertJsonPath('status', 'scheduled');
        Notification::assertSentTo(new AnonymousNotifiable, ConsultationScheduled::class);

        $matterId = $this->postJson("/api/v1/intake-requests/{$id}/accept")->assertOk()->assertJsonPath('request.status', 'accepted')->json('matter_id');

        $matter = Matter::findOrFail($matterId);
        $this->assertSame('Labor', $matter->case_type);
        $this->assertSame('maria@example.com', Client::findOrFail($matter->client_id)->email);
        $this->assertEquals($this->partner->id, $matter->responsible_lawyer_id);
        $this->assertTrue(MatterParty::where('matter_id', $matterId)->where('name', 'Pedro Penduko')->exists());

        $this->postJson("/api/v1/intake-requests/{$id}/accept")->assertStatus(422); // already accepted
    }

    public function test_a_flagged_request_cannot_be_accepted_until_the_conflict_is_resolved(): void
    {
        Client::factory()->for($this->firm)->create(['name' => 'Maria Clara Santos']);
        $this->postJson('/api/public/intake/santos-law', $this->form());
        $request = IntakeRequest::withoutGlobalScopes()->firstOrFail();

        $this->actingAs($this->partner);
        $this->postJson("/api/v1/intake-requests/{$request->id}/accept")->assertStatus(422)->assertJsonValidationErrors('conflicts');

        $flagged = ConflictCheck::where('status', 'flagged')->firstOrFail();
        $this->postJson("/api/v1/conflict-checks/{$flagged->id}/resolve", ['status' => 'waived', 'notes' => 'Same person; existing client. Consent obtained.'])->assertOk();

        $this->postJson("/api/v1/intake-requests/{$request->id}/accept")->assertOk();
    }

    public function test_declining_sends_a_reasonless_notice_and_other_firms_see_nothing(): void
    {
        $this->postJson('/api/public/intake/santos-law', $this->form());
        $id = IntakeRequest::withoutGlobalScopes()->value('id');

        $this->actingAs(User::factory()->role(Role::Partner)->create());
        $this->getJson("/api/v1/intake-requests/{$id}")->assertNotFound();

        $this->actingAs($this->partner);
        $this->postJson("/api/v1/intake-requests/{$id}/decline", ['internal_notes' => 'Conflict with existing client', 'notify' => true])->assertOk()->assertJsonPath('status', 'declined');
        Notification::assertSentTo(new AnonymousNotifiable, IntakeDeclined::class, function (IntakeDeclined $n) {
            $mail = implode(' ', $n->toMail(new AnonymousNotifiable)->introLines);

            return ! str_contains(strtolower($mail), 'conflict');
        });
    }

    public function test_firm_settings_manage_the_intake_page(): void
    {
        $this->firm->update(['slug' => null, 'intake_enabled' => false]);
        $this->actingAs(User::factory()->role(Role::ManagingPartner)->create(['firm_id' => $this->firm->id]));

        $this->putJson('/api/v1/firm', ['intake_enabled' => true])->assertStatus(422)->assertJsonValidationErrors('slug');
        $this->putJson('/api/v1/firm', ['slug' => 'Santos Law!'])->assertStatus(422)->assertJsonValidationErrors('slug');
        $this->putJson('/api/v1/firm', ['slug' => 'santos-law', 'intake_enabled' => true, 'intake_message' => 'We reply within one business day.'])
            ->assertOk()->assertJsonPath('slug', 'santos-law')->assertJsonPath('intake_enabled', true);
    }

    private function form(array $overrides = []): array
    {
        return [
            'name' => 'Maria Clara Santos',
            'email' => 'maria@example.com',
            'phone' => '0917 123 4567',
            'client_type' => 'individual',
            'case_type' => 'Labor',
            'description' => 'I was dismissed without notice after eight years of work and was not paid my final salary.',
            'opposing_parties' => [],
            'preferred_times' => [now()->addDays(2)->setTime(10, 0)->toDateTimeString()],
            'consent' => true,
            ...$overrides,
        ];
    }
}
