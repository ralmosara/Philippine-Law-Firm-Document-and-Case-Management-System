<?php

namespace Tests\Feature\Business;

use App\Domain\Business\Models\EngagementLetter;
use App\Domain\Business\Models\Prospect;
use App\Domain\Business\Notifications\EngagementLetterAnswered;
use App\Domain\Business\Notifications\EngagementLetterSent;
use App\Domain\Compliance\Enums\ConflictCheckStatus;
use App\Domain\Compliance\Models\ConflictCheck;
use App\Domain\Documents\Models\Document;
use App\Domain\Matters\Models\Client;
use App\Domain\Matters\Models\Firm;
use App\Domain\Matters\Models\Matter;
use App\Enums\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class EngagementLetterTest extends TestCase
{
    use RefreshDatabase;

    private Firm $firm;

    private User $lawyer;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-06 09:00', 'Asia/Manila'));
        $this->firm = Firm::factory()->create(['name' => 'Santos & Reyes Law', 'vat_registered' => true]);
        $this->lawyer = $this->signIn(Role::Partner, $this->firm, ['name' => 'Atty. Maria Santos']);
    }

    private function prospect(array $data = []): int
    {
        return $this->postJson('/api/v1/prospects', ['name' => 'Juan Dela Cruz', 'client_type' => 'individual', 'email' => 'juan@example.ph', 'source' => 'referral', 'case_type' => 'Civil', 'opposing_parties' => ['Pedro Reyes'], ...$data])
            ->assertCreated()->json('id');
    }

    private function draft(int $prospect): array
    {
        return $this->postJson("/api/v1/prospects/{$prospect}/engagement-letters", [
            'fee_arrangement' => 'flat',
            'fixed_fee_cents' => 15_000_000,
            'acceptance_fee_cents' => 5_000_000,
            'appearance_fee_cents' => 500_000,
            'scope' => 'Represent you as plaintiff in a collection suit against Pedro Reyes before the Regional Trial Court of Makati, up to judgment.',
        ])->assertCreated()->json();
    }

    private function sendAndCaptureToken(int $prospect, int $letter): string
    {
        $this->postJson("/api/v1/prospects/{$prospect}/engagement-letters/{$letter}/send")->assertOk()->assertJsonPath('status', 'sent');
        $token = null;
        Notification::assertSentOnDemand(EngagementLetterSent::class, function (EngagementLetterSent $n, array $channels, object $notifiable) use (&$token) {
            $token = $n->token;

            return $notifiable->routes['mail'] === 'juan@example.ph';
        });

        return $token;
    }

    public function test_the_letter_is_drafted_from_the_terms(): void
    {
        $letter = $this->draft($this->prospect());

        foreach (['Santos & Reyes Law', 'collection suit against Pedro Reyes', 'A fixed fee of ₱150,000.00', 'An acceptance fee of ₱50,000.00', 'An appearance fee of ₱5,000.00', 'subject to 12% VAT', 'BIR Form 2307', 'Data Privacy Act of 2012', 'ATTY. MARIA SANTOS', 'CONFORME'] as $expected) {
            $this->assertStringContainsString($expected, $letter['content'], "Missing: {$expected}");
        }
        $this->postJson("/api/v1/prospects/{$this->prospect(['name' => 'Ana Lim', 'email' => 'ana@example.ph'])}/engagement-letters", ['fee_arrangement' => 'contingency', 'scope' => 'x'])
            ->assertJsonValidationErrors('contingency_basis_points');
    }

    public function test_signing_opens_the_client_and_matter_with_the_fees_and_the_signed_letter(): void
    {
        $prospect = $this->prospect();
        $letter = $this->draft($prospect);
        $token = $this->sendAndCaptureToken($prospect, $letter['id']);
        $this->assertSame('engagement_sent', Prospect::find($prospect)->stage);

        $this->getJson("/api/public/engagement/{$token}")->assertOk()->assertJsonPath('firm', 'Santos & Reyes Law')->assertJsonPath('status', 'sent');
        $this->postJson("/api/public/engagement/{$token}/sign", ['signer_name' => 'Juan Dela Cruz', 'method' => 'drawn', 'signature_image' => 'data:image/png;base64,AAAA', 'consent' => true])
            ->assertJsonValidationErrors('signature_image');
        $this->postJson("/api/public/engagement/{$token}/sign", ['signer_name' => 'Juan Dela Cruz', 'method' => 'typed'])->assertJsonValidationErrors('consent');
        $this->postJson("/api/public/engagement/{$token}/sign", ['signer_name' => 'Juan Dela Cruz', 'method' => 'typed', 'consent' => true])->assertOk();

        $p = Prospect::find($prospect);
        $this->assertSame('won', $p->stage);
        $matter = Matter::findOrFail($p->matter_id);
        $this->assertSame(['flat', 15_000_000, 5_000_000, 500_000], [$matter->fee_arrangement->value, $matter->fixed_fee_cents, $matter->acceptance_fee_cents, $matter->appearance_fee_cents]);
        $this->assertSame('juan@example.ph', Client::findOrFail($matter->client_id)->email);

        $signed = EngagementLetter::find($letter['id']);
        $this->assertSame(['signed', 'Juan Dela Cruz', 'typed', $matter->id], [$signed->status, $signed->signer_name, $signed->signature_method, $signed->matter_id]);
        $document = Document::findOrFail($signed->document_id);
        $this->assertSame('signed', $document->status->value);
        $this->assertStringContainsString('/s/ Juan Dela Cruz', $document->versions()->latest('version_number')->first()->content);
        Notification::assertSentTo($this->lawyer, EngagementLetterAnswered::class, fn ($n) => $n->matter?->id === $matter->id);

        $this->getJson("/api/public/engagement/{$token}")->assertNotFound();
    }

    public function test_the_prospect_can_decline(): void
    {
        $prospect = $this->prospect();
        $letter = $this->draft($prospect);
        $token = $this->sendAndCaptureToken($prospect, $letter['id']);

        $this->postJson("/api/public/engagement/{$token}/decline", ['reason' => 'The fees are too high'])->assertOk();
        $this->assertSame('declined', EngagementLetter::find($letter['id'])->status);
        $this->assertSame('engagement_sent', Prospect::find($prospect)->stage);
        Notification::assertSentTo($this->lawyer, EngagementLetterAnswered::class, fn ($n) => $n->letter->decline_reason === 'The fees are too high');
    }

    public function test_it_is_not_sent_while_a_conflict_is_flagged_or_without_an_email(): void
    {
        Client::factory()->for($this->firm)->create(['name' => 'Pedro Reyes']);
        $prospect = $this->prospect();
        $this->assertSame(ConflictCheckStatus::Flagged, ConflictCheck::whereIn('id', Prospect::find($prospect)->conflict_check_ids)->where('search_term', 'Pedro Reyes')->value('status'));
        $letter = $this->draft($prospect);
        $this->postJson("/api/v1/prospects/{$prospect}/engagement-letters/{$letter['id']}/send")->assertJsonValidationErrors('letter');

        $noEmail = $this->prospect(['name' => 'Rosa Tan', 'email' => null, 'opposing_parties' => []]);
        $l2 = $this->draft($noEmail);
        $this->postJson("/api/v1/prospects/{$noEmail}/engagement-letters/{$l2['id']}/send")->assertJsonValidationErrors('letter');
    }

    public function test_expired_links_cannot_be_signed_and_staff_need_to_be_lawyers(): void
    {
        $prospect = $this->prospect();
        $letter = $this->draft($prospect);
        $token = $this->sendAndCaptureToken($prospect, $letter['id']);

        $this->travelTo(CarbonImmutable::parse('2026-11-20 09:00', 'Asia/Manila'));
        $this->getJson("/api/public/engagement/{$token}")->assertJsonPath('status', 'expired');
        $this->postJson("/api/public/engagement/{$token}/sign", ['signer_name' => 'Juan Dela Cruz', 'method' => 'typed', 'consent' => true])->assertJsonValidationErrors('letter');

        $this->signIn(Role::Paralegal, $this->firm);
        $this->getJson("/api/v1/prospects/{$prospect}/engagement-letters")->assertForbidden();
    }
}
