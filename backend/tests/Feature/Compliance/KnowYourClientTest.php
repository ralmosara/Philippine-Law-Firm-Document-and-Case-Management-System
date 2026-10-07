<?php

namespace Tests\Feature\Compliance;

use App\Domain\Compliance\Models\AmlReview;
use App\Domain\Compliance\Models\ClientIdentification;
use App\Domain\Compliance\Notifications\AmlReviewNeeded;
use App\Domain\Compliance\Notifications\IdentificationExpiring;
use App\Domain\Compliance\Services\KnowYourClient;
use App\Domain\Matters\Models\Client;
use App\Domain\Matters\Models\Firm;
use App\Domain\Matters\Models\Matter;
use App\Domain\Trust\Models\TrustAccount;
use App\Domain\Trust\Services\TrustLedgerService;
use App\Enums\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class KnowYourClientTest extends TestCase
{
    use RefreshDatabase;

    private Firm $firm;

    private User $partner;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Storage::fake('local');
        $this->travelTo(CarbonImmutable::parse('2026-10-06 09:00', 'Asia/Manila'));
        $this->firm = Firm::factory()->create();
        $this->partner = $this->signIn(Role::ManagingPartner, $this->firm);
        $this->client = Client::factory()->for($this->firm)->create(['type' => 'corporate', 'name' => 'Visayas Holdings Inc.']);
    }

    private function addId(array $data = [])
    {
        return $this->post("/api/v1/clients/{$this->client->id}/kyc/identifications", ['id_type' => 'SEC certificate of registration', 'id_number' => 'CS201912345', ...$data], ['Accept' => 'application/json']);
    }

    public function test_identification_owners_and_review_are_recorded(): void
    {
        $problems = $this->getJson("/api/v1/clients/{$this->client->id}/kyc")->assertOk()->json('problems');
        $this->assertSame(['No identification on file.', 'Beneficial owners not recorded.', 'Not yet reviewed and risk-rated.'], $problems);

        $this->addId(['id_type' => 'Library card'])->assertJsonValidationErrors('id_type');
        $this->addId(['expires_on' => '2030-01-31', 'scan' => UploadedFile::fake()->create('sec.pdf', 30, 'application/pdf')])->assertCreated();
        $id = ClientIdentification::sole();
        Storage::disk('local')->assertExists($id->path);
        $this->get("/api/v1/clients/{$this->client->id}/kyc/identifications/{$id->id}/scan")->assertOk();

        $this->postJson("/api/v1/clients/{$this->client->id}/kyc/owners", ['name' => 'Ramon Uy', 'ownership_bps' => 6000, 'position' => 'President', 'nationality' => 'Filipino'])->assertCreated();
        $this->putJson("/api/v1/clients/{$this->client->id}/kyc/review", ['risk' => 'high', 'is_pep' => false])->assertJsonValidationErrors('notes');
        $this->putJson("/api/v1/clients/{$this->client->id}/kyc/review", ['risk' => 'high', 'is_pep' => true, 'notes' => 'President is a former mayor; source of funds documented.'])
            ->assertOk()->assertJsonPath('problems', [])->assertJsonPath('risk', 'high')->assertJsonPath('owners.0.name', 'Ramon Uy');

        $this->signIn(Role::Paralegal, $this->firm);
        $this->getJson("/api/v1/clients/{$this->client->id}/kyc")->assertForbidden();
    }

    public function test_lawyers_are_told_before_an_id_expires_and_when_it_has(): void
    {
        $lawyer = User::factory()->role(Role::Associate)->create(['firm_id' => $this->firm->id]);
        Matter::factory()->for($this->client)->create(['responsible_lawyer_id' => $lawyer->id, 'status' => 'filed']);
        $this->addId(['expires_on' => '2026-10-20'])->assertCreated();
        $kyc = app(KnowYourClient::class);

        $this->assertSame(1, $kyc->remindExpiring(CarbonImmutable::parse('2026-10-06')));
        $this->assertSame(0, $kyc->remindExpiring(CarbonImmutable::parse('2026-10-07')), 'Once per stage.');
        $this->assertSame(1, $kyc->remindExpiring(CarbonImmutable::parse('2026-10-21')));
        Notification::assertSentToTimes($lawyer, IdentificationExpiring::class, 2);
        Notification::assertSentTo($this->partner, IdentificationExpiring::class);
    }

    public function test_large_trust_deposits_on_one_day_are_flagged_for_review(): void
    {
        $this->firm->update(['aml_threshold_cents' => 50_000_000]);
        $account = TrustAccount::factory()->for($this->firm)->create(['client_id' => $this->client->id]);
        $ledger = app(TrustLedgerService::class);

        $ledger->deposit($account, 30_000_000, 'First tranche', by: $this->partner);
        $this->assertSame(0, AmlReview::count());
        $ledger->deposit($account, 25_000_000, 'Second tranche', by: $this->partner);   // ₱550,000 that day
        $ledger->deposit($account, 1_000_000, 'Third', by: $this->partner);

        $review = AmlReview::sole();
        $this->assertSame([56_000_000, 3], [$review->amount_cents, count($review->trust_transaction_ids)]);
        Notification::assertSentToTimes($this->partner, AmlReviewNeeded::class, 1);

        $this->getJson('/api/v1/aml-reviews')->assertOk()->assertJsonPath('data.0.amount_cents', 56_000_000);
        $this->postJson("/api/v1/aml-reviews/{$review->id}/decide", ['status' => 'reported', 'notes' => 'Cash deposit; CTR filed.'])->assertJsonValidationErrors('report_reference');
        $this->postJson("/api/v1/aml-reviews/{$review->id}/decide", ['status' => 'not_reportable', 'notes' => 'Bank transfer from the client\'s own account; not a cash transaction.'])->assertOk();
        $this->getJson('/api/v1/aml-reviews')->assertJsonCount(0, 'data');

        // The next day starts afresh.
        $this->travelTo(CarbonImmutable::parse('2026-10-07 09:00', 'Asia/Manila'));
        $ledger->deposit($account, 1_000_000, 'Small', by: $this->partner);
        $this->assertSame(1, AmlReview::count());
    }

    public function test_the_threshold_is_the_firms_to_set(): void
    {
        $this->putJson('/api/v1/firm', ['aml_threshold_cents' => 75_000_000])->assertOk()->assertJsonPath('aml_threshold_cents', 75_000_000);
        $this->assertNotNull($this->firm->fresh()->aml_threshold_confirmed_at);
    }
}
