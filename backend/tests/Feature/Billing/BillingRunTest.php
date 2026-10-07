<?php

namespace Tests\Feature\Billing;

use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\TimeEntry;
use App\Domain\Billing\Notifications\InvoiceIssued;
use App\Domain\Matters\Models\Client;
use App\Domain\Matters\Models\Firm;
use App\Domain\Matters\Models\Matter;
use App\Enums\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class BillingRunTest extends TestCase
{
    use RefreshDatabase;

    private User $partner;

    private Matter $a;

    private Matter $b;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-31 09:00', 'Asia/Manila'));
        $firm = Firm::factory()->create();
        $this->partner = $this->signIn(Role::ManagingPartner, $firm);
        $this->a = Matter::factory()->for(Client::factory()->for($firm)->create(['name' => 'Ana Cruz', 'email' => 'ana@example.ph']))->create();
        $this->b = Matter::factory()->for(Client::factory()->for($firm)->create(['name' => 'Ben Reyes', 'email' => null]))->create();
        TimeEntry::factory()->for($this->a)->create(['user_id' => $this->partner->id, 'minutes' => 60, 'rate_cents' => 500_000, 'work_date' => '2026-10-10']);
        TimeEntry::factory()->for($this->a)->create(['user_id' => $this->partner->id, 'minutes' => 30, 'rate_cents' => 500_000, 'work_date' => '2026-11-02']);
        TimeEntry::factory()->for($this->b)->create(['user_id' => $this->partner->id, 'minutes' => 120, 'rate_cents' => 400_000, 'work_date' => '2026-10-15']);
        TimeEntry::factory()->for($this->b)->create(['user_id' => $this->partner->id, 'minutes' => 60, 'rate_cents' => 400_000, 'is_billable' => false]);
    }

    public function test_draft_every_selected_matter_then_issue_and_email_them_together(): void
    {
        $list = collect($this->getJson('/api/v1/billing-run?through=2026-10-31')->assertOk()->json('matters'))->keyBy('matter.id');
        $this->assertSame([1, 500_000], [$list[$this->a->id]['time_entries'], $list[$this->a->id]['time_cents']], 'Work after the cut-off date waits for next month.');
        $this->assertSame(800_000, $list[$this->b->id]['time_cents'], 'Non-billable time is not listed.');

        $results = $this->postJson('/api/v1/billing-run/draft', ['matter_ids' => [$this->a->id, $this->b->id], 'due_in_days' => 15, 'through' => '2026-10-31'])->assertOk()->json('results');
        $this->assertSame([null, null], array_column($results, 'error'));
        $this->assertSame(2, Invoice::where('status', 'draft')->count());
        $this->assertSame(1, TimeEntry::where('matter_id', $this->a->id)->whereNull('invoice_id')->where('is_billable', true)->count(), 'November work stays unbilled.');

        // Drafting again: nothing left to bill up to the cut-off, reported per matter.
        $again = $this->postJson('/api/v1/billing-run/draft', ['matter_ids' => [$this->b->id], 'due_in_days' => 15, 'through' => '2026-10-31'])->json('results');
        $this->assertNotNull($again[0]['error']);

        $ids = Invoice::where('status', 'draft')->pluck('id')->all();
        $issued = $this->postJson('/api/v1/billing-run/issue', ['invoice_ids' => $ids, 'email' => true])->assertOk()->json('results');
        $this->assertSame([true, true], array_column($issued, 'issued'));
        $this->assertSame(1, collect($issued)->where('emailed', true)->count(), 'The client without an email is issued, not emailed.');
        Notification::assertSentTo(Client::where('name', 'Ana Cruz')->first(), InvoiceIssued::class);
        $this->assertSame(0, Invoice::where('status', 'draft')->count());
    }

    public function test_only_finance_runs_billing(): void
    {
        $this->signIn(Role::Associate, $this->partner->firm()->first());
        $this->getJson('/api/v1/billing-run')->assertForbidden();
    }
}
