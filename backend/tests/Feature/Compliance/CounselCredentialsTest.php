<?php

namespace Tests\Feature\Compliance;

use App\Domain\Compliance\Notifications\CredentialsRenewalDue;
use App\Domain\Compliance\Services\CounselCredentials;
use App\Domain\Matters\Models\Client;
use App\Domain\Matters\Models\Firm;
use App\Domain\Matters\Models\Matter;
use App\Enums\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class CounselCredentialsTest extends TestCase
{
    use RefreshDatabase;

    private Firm $firm;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-06 09:00', 'Asia/Manila'));
        $this->firm = Firm::factory()->create();
    }

    private function lawyer(array $attributes = []): User
    {
        return User::factory()->role(Role::Associate)->create(['firm_id' => $this->firm->id, 'ptr_number' => '7654321', 'ptr_date' => '2026-01-06', 'ptr_place' => 'Makati City', 'ibp_number' => '123456', 'ibp_date' => '2026-01-05', 'ibp_chapter' => 'Makati', ...$attributes]);
    }

    public function test_out_of_date_or_missing_details_are_named(): void
    {
        $check = app(CounselCredentials::class);

        $this->assertSame([], $check->problems($this->lawyer()));
        $this->assertSame(['The PTR is for 2025, not 2026.', 'IBP dues are paid for 2025, not 2026.'], $check->problems($this->lawyer(['ptr_date' => '2025-01-10', 'ibp_date' => '2025-01-10'])));
        $this->assertSame([], $check->problems($this->lawyer(['ibp_date' => null, 'ibp_lifetime' => true])), 'Lifetime members pay no annual dues.');
        $this->assertSame(['No PTR number recorded.', 'The IBP payment date is not recorded, so it cannot be checked.'], $check->problems($this->lawyer(['ptr_number' => null, 'ibp_date' => null])));
    }

    public function test_pleadings_print_the_dates_and_warn_when_out_of_date(): void
    {
        $lawyer = $this->signIn(Role::Partner, $this->firm, ['ptr_number' => '7654321', 'ptr_date' => '2025-01-06', 'ptr_place' => 'Makati City', 'ibp_number' => '0123', 'ibp_lifetime' => true, 'ibp_chapter' => 'Makati']);
        $matter = Matter::factory()->for(Client::factory()->for($this->firm))->create(['responsible_lawyer_id' => $lawyer->id]);

        $response = $this->postJson("/api/v1/matters/{$matter->id}/pleadings/preview", ['type' => 'motion'])->assertOk();
        $this->assertStringContainsString('PTR No. 7654321, 01/06/2025, Makati City', $response->json('text'));
        $this->assertStringContainsString('IBP Lifetime Member No. 0123, Makati', $response->json('text'));
        $this->assertSame(["{$lawyer->name}: The PTR is for 2025, not 2026."], $response->json('warnings'));

        $this->putJson('/api/v1/auth/credentials', ['ptr_date' => '2026-01-07'])->assertOk()->assertJsonPath('user.credential_problems', []);
        $this->putJson('/api/v1/auth/credentials', ['ptr_date' => '2027-01-07'])->assertJsonValidationErrors('ptr_date');
    }

    public function test_lawyers_are_reminded_on_the_january_days_only(): void
    {
        Notification::fake();
        $stale = $this->lawyer(['ptr_date' => '2026-01-06', 'ibp_date' => '2026-01-05']);
        $current = $this->lawyer(['ptr_date' => '2027-01-03', 'ibp_date' => '2027-01-03']);
        $paralegal = User::factory()->role(Role::Paralegal)->create(['firm_id' => $this->firm->id]);
        $credentials = app(CounselCredentials::class);

        $this->assertSame(0, $credentials->sendReminders(CarbonImmutable::parse('2027-01-03')));
        $this->assertSame(1, $credentials->sendReminders(CarbonImmutable::parse('2027-01-02')));

        Notification::assertSentTo($stale, CredentialsRenewalDue::class, fn ($n) => $n->year === 2027 && count($n->problems) === 2);
        Notification::assertNotSentTo([$current, $paralegal], CredentialsRenewalDue::class);
    }
}
