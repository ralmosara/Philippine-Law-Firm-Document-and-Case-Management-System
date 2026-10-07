<?php

namespace Tests\Feature\Compliance;

use App\Domain\Compliance\Models\ConflictCheck;
use App\Domain\Compliance\Models\ConflictWaiver;
use App\Domain\Compliance\Notifications\ConflictWaiverAnswered;
use App\Domain\Compliance\Notifications\ConflictWaiverRequested;
use App\Domain\Matters\Models\Client;
use App\Domain\Matters\Models\Firm;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ConflictWaiverTest extends TestCase
{
    use RefreshDatabase;

    private User $lawyer;

    private ConflictCheck $check;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $firm = Firm::factory()->create(['name' => 'Santos & Reyes Law']);
        $this->lawyer = $this->signIn(Role::Partner, $firm);
        Client::factory()->for($firm)->create(['name' => 'Visayas Freight Inc.']);
        $id = $this->postJson('/api/v1/conflict-checks', ['name' => 'Visayas Freight Inc.'])->assertCreated()->json('id');
        $this->check = ConflictCheck::findOrFail($id);
    }

    private function send(): string
    {
        $content = $this->postJson("/api/v1/conflict-checks/{$this->check->id}/waivers/draft", [
            'signer_name' => 'Visayas Freight Inc.',
            'situation' => 'We act for you in your trademark registration. Luzon Shipping Corp. has asked us to act for it in a collection case against you.',
            'explanation' => 'The two matters are unrelated. Different lawyers will handle each, and nothing you told us will be shared.',
        ])->assertOk()->json('content');
        $this->assertStringContainsString('written informed consent of all concerned', $content);

        $this->postJson("/api/v1/conflict-checks/{$this->check->id}/waivers", ['signer_name' => 'Visayas Freight Inc.', 'signer_email' => 'legal@visayas.ph', 'content' => $content])->assertCreated();
        $token = null;
        Notification::assertSentOnDemand(ConflictWaiverRequested::class, function ($n) use (&$token) {
            $token = $n->token;

            return true;
        });

        return $token;
    }

    public function test_the_person_signs_online_and_the_consent_is_kept_with_the_check(): void
    {
        $token = $this->send();

        $this->getJson("/api/public/consent/{$token}")->assertOk()->assertJsonPath('firm', 'Santos & Reyes Law')->assertJsonPath('status', 'sent');
        $this->postJson("/api/public/consent/{$token}/sign", ['signer_name' => 'Maria Uy, General Counsel', 'method' => 'typed', 'consent' => true])->assertOk();
        $this->getJson("/api/public/consent/{$token}")->assertNotFound();

        $this->assertSame('signed', ConflictWaiver::sole()->status);
        Notification::assertSentTo($this->lawyer, ConflictWaiverAnswered::class);
        $this->getJson("/api/v1/conflict-checks/{$this->check->id}/waivers")->assertOk()->assertJsonPath('0.signed_name', 'Maria Uy, General Counsel');
        $this->get("/api/v1/conflict-checks/{$this->check->id}/pdf")->assertOk();
    }

    public function test_declining_and_withdrawing(): void
    {
        $token = $this->send();
        $this->postJson("/api/public/consent/{$token}/decline", ['reason' => 'We prefer that you not act against us.'])->assertOk();
        $this->assertSame('declined', ConflictWaiver::sole()->status);

        $this->send();
        $second = ConflictWaiver::where('status', 'sent')->sole();
        $this->postJson("/api/v1/conflict-waivers/{$second->id}/cancel")->assertOk()->assertJsonPath('status', 'cancelled');
    }
}
