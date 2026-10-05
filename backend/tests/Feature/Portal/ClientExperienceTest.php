<?php

namespace Tests\Feature\Portal;

use App\Domain\Deadlines\Models\MatterDeadline;
use App\Domain\Feedback\MatterFeedback;
use App\Domain\Feedback\Notifications\FeedbackRequested;
use App\Domain\Feedback\Notifications\LowRatingReceived;
use App\Domain\Matters\Enums\MatterStatus;
use App\Domain\Matters\Models\Client;
use App\Domain\Matters\Models\Firm;
use App\Domain\Matters\Models\Matter;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ClientExperienceTest extends TestCase
{
    use RefreshDatabase;

    private Firm $firm;

    private User $lawyer;

    private User $managingPartner;

    private Client $client;

    private Matter $matter;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->firm = Firm::factory()->create(['name' => 'Santos Law']);
        $this->managingPartner = User::factory()->create(['firm_id' => $this->firm->id, 'role' => Role::ManagingPartner]);
        $this->lawyer = $this->signIn(Role::Partner, $this->firm);
        $this->client = Client::factory()->for($this->firm)->withPortal('secret-pass')->create(['name' => 'Juan Dela Cruz', 'email' => 'juan@example.com']);
        $this->matter = Matter::factory()->for($this->client)->create(['responsible_lawyer_id' => $this->lawyer->id, 'case_type' => 'Labor', 'status' => MatterStatus::Decision]);
    }

    private function close(array $extra = []): void
    {
        $this->postJson("/api/v1/matters/{$this->matter->id}/status", ['status' => 'closed', 'reason' => 'Judgment satisfied', ...$extra])->assertOk();
    }

    public function test_closing_asks_the_client_for_feedback_once(): void
    {
        $this->close();
        Notification::assertSentTo($this->client, FeedbackRequested::class);
        $this->assertSame(1, MatterFeedback::count());

        // Reopened and closed again: not asked twice.
        $this->postJson("/api/v1/matters/{$this->matter->id}/status", ['status' => 'intake', 'reason' => 'New issue'])->assertOk();
        $this->postJson("/api/v1/matters/{$this->matter->id}/status", ['status' => 'closed', 'reason' => 'Done'])->assertOk();
        Notification::assertSentTimes(FeedbackRequested::class, 1);
    }

    public function test_the_lawyer_can_choose_not_to_ask_and_clients_without_the_portal_are_not_asked(): void
    {
        $this->close(['ask_feedback' => false]);
        $this->assertSame(0, MatterFeedback::count());

        $offline = Matter::factory()->for(Client::factory()->for($this->firm))->create(['status' => MatterStatus::Decision]);
        $this->postJson("/api/v1/matters/{$offline->id}/status", ['status' => 'closed', 'reason' => 'Done'])->assertOk();
        $this->assertSame(0, MatterFeedback::count());
        Notification::assertNothingSent();
    }

    public function test_the_client_answers_in_the_portal_and_a_low_rating_alerts_the_firm(): void
    {
        $this->close();
        $this->actingAs($this->client, 'client');
        $id = $this->getJson("/api/portal/matters/{$this->matter->id}")->assertJsonPath('feedback.rating', null)->json('feedback.id');

        $this->postJson("/api/portal/feedback/{$id}", ['rating' => 6])->assertStatus(422)->assertJsonValidationErrors('rating');
        $this->postJson("/api/portal/feedback/{$id}", ['rating' => 2, 'comment' => 'Hard to reach my lawyer.'])->assertOk()->assertJsonPath('rating', 2);
        Notification::assertSentTo([$this->lawyer, $this->managingPartner], LowRatingReceived::class);

        // Another client cannot answer it.
        $other = Client::factory()->for($this->firm)->withPortal('other-secret-pass')->create();
        $this->actingAs($other, 'client');
        $this->postJson("/api/portal/feedback/{$id}", ['rating' => 5])->assertNotFound();

        // The firm sees it, follows up; the client can no longer change it.
        $this->actingAs($this->lawyer, 'web')->actingAs($this->lawyer, 'sanctum');
        $report = $this->getJson('/api/v1/feedback')->assertOk()->json();
        $this->assertSame(['requested' => 1, 'answered' => 1, 'response_rate' => 100, 'average' => 2, 'low' => 1], $report['summary']);
        $this->assertSame(1, $report['low_open']);
        $this->assertSame('Labor', $report['by_practice_area'][0]['name']);
        $this->assertSame($this->lawyer->name, $report['by_lawyer'][0]['name']);
        $this->postJson("/api/v1/feedback/{$id}/follow-up", ['note' => 'Called him; agreed a weekly update.'])->assertOk();
        $this->assertSame(0, $this->getJson('/api/v1/feedback')->json('low_open'));

        $this->actingAs($this->client, 'client');
        $this->getJson("/api/portal/matters/{$this->matter->id}")->assertJsonPath('feedback.can_change', false);
        $this->postJson("/api/portal/feedback/{$id}", ['rating' => 5])->assertStatus(422);
    }

    public function test_a_good_rating_does_not_alert_and_staff_without_practice_rights_cannot_see_the_report(): void
    {
        $this->close();
        $this->actingAs($this->client, 'client');
        $id = MatterFeedback::firstOrFail()->id;
        $this->postJson("/api/portal/feedback/{$id}", ['rating' => 5])->assertOk();
        Notification::assertNotSentTo($this->lawyer, LowRatingReceived::class);

        $this->signIn(Role::Paralegal, $this->firm);
        $this->getJson('/api/v1/feedback')->assertForbidden();
    }

    public function test_the_client_subscribes_to_their_own_hearings_only(): void
    {
        MatterDeadline::factory()->for($this->matter)->create(['kind' => 'hearing', 'title' => 'Pre-trial conference', 'due_date' => today()->addDays(10), 'due_time' => '09:30:00', 'location' => 'RTC Branch 143, Makati']);
        MatterDeadline::factory()->for($this->matter)->create(['kind' => 'filing', 'title' => 'Internal: file reply', 'due_date' => today()->addDays(5)]);
        $otherMatter = Matter::factory()->for(Client::factory()->for($this->firm))->create();
        MatterDeadline::factory()->for($otherMatter)->create(['kind' => 'hearing', 'title' => 'Someone else\'s hearing', 'due_date' => today()->addDays(3)]);

        $this->actingAs($this->client, 'client');
        $this->getJson('/api/portal/calendar')->assertJsonPath('enabled', false);
        $url = $this->postJson('/api/portal/calendar')->assertCreated()->json('url');
        $this->assertMatchesRegularExpression('#/api/portal-calendar/[A-Za-z0-9]{40}\.ics$#', $url);

        $ics = $this->get(parse_url($url, PHP_URL_PATH))->assertOk()->assertHeader('Content-Type', 'text/calendar; charset=utf-8')->getContent();
        $this->assertStringContainsString('Pre-trial conference', $ics);
        $this->assertStringContainsString('RTC Branch 143\, Makati', $ics);
        $this->assertStringNotContainsString('Internal: file reply', $ics);
        $this->assertStringNotContainsString('Someone else', $ics);

        // In the client's language.
        $this->client->forceFill(['locale' => 'fil'])->save();
        $this->assertStringContainsString('Pagdinig:', $this->get(parse_url($url, PHP_URL_PATH))->getContent());

        // Replacing the link, or withdrawing portal access, stops the old one.
        $this->postJson('/api/portal/calendar')->assertCreated();
        $this->get(parse_url($url, PHP_URL_PATH))->assertNotFound();
        $fresh = $this->postJson('/api/portal/calendar')->json('url');
        $this->client->forceFill(['portal_enabled' => false])->save();
        $this->get(parse_url($fresh, PHP_URL_PATH))->assertNotFound();
    }
}
