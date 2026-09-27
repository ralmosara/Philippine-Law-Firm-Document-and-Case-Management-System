<?php

namespace Tests\Feature\Billing;

use App\Domain\Billing\Collections\Collections;
use App\Domain\Billing\Collections\InvoiceReminder;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\TimeEntry;
use App\Domain\Billing\Notifications\InvoiceIssued;
use App\Domain\Billing\Notifications\PaymentReminder;
use App\Domain\Billing\Services\InvoiceGenerator;
use App\Domain\Billing\Services\InvoicePayments;
use App\Domain\Matters\Models\Client;
use App\Domain\Matters\Models\Firm;
use App\Domain\Matters\Models\Matter;
use App\Domain\Trust\Models\TrustAccount;
use App\Domain\Trust\Notifications\TrustReplenishmentRequested;
use App\Domain\Trust\Services\TrustLedgerService;
use App\Enums\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class CollectionsTest extends TestCase
{
    use RefreshDatabase;

    private Firm $firm;

    private Client $client;

    private User $partner;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-01 09:00', 'Asia/Manila'));
        $this->firm = Firm::factory()->create(['vat_registered' => true, 'payment_reminders_enabled' => true]);
        $this->client = Client::factory()->for($this->firm)->create(['email' => 'accounts@client.ph']);
        $this->partner = $this->signIn(Role::ManagingPartner, $this->firm);
    }

    private function collections(): Collections
    {
        return app(Collections::class);
    }

    private function issuedInvoice(int $dueInDays = 30): Invoice
    {
        $matter = Matter::factory()->for($this->client)->create();
        TimeEntry::factory()->for($matter)->create(['user_id' => $this->partner->id, 'minutes' => 60, 'rate_cents' => 1_000_000]);
        $generator = app(InvoiceGenerator::class);

        return $generator->issue($generator->generateForMatter($matter, $this->partner, dueInDays: $dueInDays));
    }

    public function test_retainers_are_billed_once_a_month_on_their_billing_day(): void
    {
        $matter = Matter::factory()->for($this->client)->create(['responsible_lawyer_id' => $this->partner->id]);
        $matter->forceFill(['fee_arrangement' => 'retainer', 'fixed_fee_cents' => 5_000_000, 'retainer_auto_bill' => true, 'retainer_billing_day' => 5])->save();

        $this->collections()->billRetainers(CarbonImmutable::parse('2026-10-04'));
        $this->assertSame(0, Invoice::count());

        $this->collections()->billRetainers(CarbonImmutable::parse('2026-10-05'));
        $this->collections()->billRetainers(CarbonImmutable::parse('2026-10-06')); // already billed for October
        $this->assertSame(1, Invoice::count());

        $invoice = Invoice::with('lines')->firstOrFail();
        $this->assertSame('draft', $invoice->status->value); // left for review by default
        $this->assertSame('Monthly retainer for October 2026', $invoice->lines->first()->description);
        $this->assertSame(5_000_000 + 600_000, $invoice->total_cents); // 12% VAT on the fee
        $this->assertSame('2026-10-01', $matter->fresh()->retainer_billed_through->toDateString());

        $this->collections()->billRetainers(CarbonImmutable::parse('2026-11-05'));
        $this->assertSame(2, Invoice::count());
        Notification::assertNothingSent();
    }

    public function test_auto_issued_retainers_are_sent_to_the_client(): void
    {
        $matter = Matter::factory()->for($this->client)->create(['responsible_lawyer_id' => $this->partner->id]);
        $matter->forceFill(['fee_arrangement' => 'retainer', 'fixed_fee_cents' => 2_000_000, 'retainer_auto_bill' => true, 'retainer_auto_issue' => true])->save();

        Artisan::call('billing:collections');

        $this->assertSame('issued', Invoice::firstOrFail()->status->value);
        Notification::assertSentTo($this->client, InvoiceIssued::class);
    }

    public function test_closed_or_opted_out_retainers_are_not_billed(): void
    {
        $closed = Matter::factory()->for($this->client)->create();
        $closed->forceFill(['fee_arrangement' => 'retainer', 'fixed_fee_cents' => 100_000, 'retainer_auto_bill' => true, 'status' => 'closed'])->save();
        $manual = Matter::factory()->for($this->client)->create();
        $manual->forceFill(['fee_arrangement' => 'retainer', 'fixed_fee_cents' => 100_000, 'retainer_auto_bill' => false])->save();

        $this->assertSame(0, $this->collections()->billRetainers(CarbonImmutable::today()));
    }

    public function test_reminders_go_out_before_the_due_date_then_a_week_and_a_month_overdue(): void
    {
        $invoice = $this->issuedInvoice(dueInDays: 2);

        $this->assertSame(1, $this->collections()->sendReminders(CarbonImmutable::today()));
        $this->assertSame(0, $this->collections()->sendReminders(CarbonImmutable::today())); // once per stage
        Notification::assertSentTo($this->client, PaymentReminder::class, fn ($n) => $n->stage === InvoiceReminder::DUE_SOON);

        $this->assertSame(0, $this->collections()->sendReminders(CarbonImmutable::today()->addDays(5))); // 3 days overdue: nothing new
        $this->assertSame(1, $this->collections()->sendReminders(CarbonImmutable::today()->addDays(9)));
        $this->assertSame(1, $this->collections()->sendReminders(CarbonImmutable::today()->addDays(40)));
        $this->assertSame([InvoiceReminder::DUE_SOON, InvoiceReminder::OVERDUE_7, InvoiceReminder::OVERDUE_30], InvoiceReminder::orderBy('id')->pluck('stage')->all());
        $this->assertSame(1_120_000, InvoiceReminder::first()->balance_cents);
    }

    public function test_no_reminders_when_off_paused_paid_or_without_an_email(): void
    {
        $invoice = $this->issuedInvoice(dueInDays: -10);

        $this->firm->update(['payment_reminders_enabled' => false]);
        $this->assertSame(0, $this->collections()->sendReminders(CarbonImmutable::today()));
        $this->firm->update(['payment_reminders_enabled' => true]);

        $this->postJson("/api/v1/invoices/{$invoice->id}/reminders-paused", ['paused' => true])->assertOk();
        $this->assertSame(0, $this->collections()->sendReminders(CarbonImmutable::today()));
        $this->postJson("/api/v1/invoices/{$invoice->id}/reminders-paused", ['paused' => false])->assertOk();

        app(InvoicePayments::class)->settleInFull($invoice, $this->partner);
        $this->assertSame(0, $this->collections()->sendReminders(CarbonImmutable::today()));

        $other = Client::factory()->for($this->firm)->create(['email' => null]);
        $noEmail = $this->issuedInvoice(dueInDays: -10);
        $noEmail->forceFill(['client_id' => $other->id])->save();
        $this->assertSame(0, $this->collections()->sendReminders(CarbonImmutable::today()));

        Notification::assertNothingSent();
    }

    public function test_a_reminder_can_be_sent_by_hand_once_a_day(): void
    {
        $invoice = $this->issuedInvoice();

        $this->postJson("/api/v1/invoices/{$invoice->id}/remind")->assertCreated();
        $this->postJson("/api/v1/invoices/{$invoice->id}/remind")->assertStatus(422);
        Notification::assertSentToTimes($this->client, PaymentReminder::class, 1);

        $this->getJson("/api/v1/invoices/{$invoice->id}")->assertJsonPath('reminders.0.label', 'Sent by hand');

        $this->signIn(Role::Associate, $this->firm);
        $this->postJson("/api/v1/invoices/{$invoice->id}/remind")->assertForbidden();
    }

    public function test_the_reminder_email_attaches_the_billing_statement(): void
    {
        $invoice = $this->issuedInvoice(dueInDays: -8);
        $mail = (new PaymentReminder($invoice, InvoiceReminder::OVERDUE_7))->toMail($this->client);

        $this->assertStringContainsString('past due', $mail->subject);
        $this->assertStringContainsString('₱11,200.00', implode(' ', $mail->introLines));
        $this->assertCount(1, $mail->rawAttachments);
        $this->assertSame("billing-statement-{$invoice->number}.pdf", $mail->rawAttachments[0]['name']);
        $this->assertStringStartsWith('%PDF', $mail->rawAttachments[0]['data']);
    }

    public function test_low_trust_deposits_prompt_a_weekly_top_up_request(): void
    {
        $account = TrustAccount::factory()->create(['client_id' => $this->client->id]);
        app(TrustLedgerService::class)->deposit($account, 1_000_000, 'Deposit');

        $this->putJson("/api/v1/trust-accounts/{$account->id}/minimum-balance", ['minimum_balance_cents' => 5_000_000])->assertOk();

        $this->assertSame(1, $this->collections()->requestReplenishments());
        $this->assertSame(0, $this->collections()->requestReplenishments()); // not again this week
        Notification::assertSentTo($this->client, TrustReplenishmentRequested::class, fn ($n) => $n->shortfallCents === 4_000_000);

        $this->travel(8)->days();
        $this->assertSame(1, $this->collections()->requestReplenishments());

        // Topped up: the request is cleared, so a later shortfall starts afresh.
        app(TrustLedgerService::class)->deposit($account->fresh(), 5_000_000, 'Top-up');
        $this->collections()->requestReplenishments();
        $this->assertNull($account->fresh()->replenishment_requested_at);
    }

    public function test_the_collections_overview_lists_what_needs_following_up(): void
    {
        $overdue = $this->issuedInvoice(dueInDays: -10);
        $this->issuedInvoice(dueInDays: 30); // not yet relevant
        $account = TrustAccount::factory()->create(['client_id' => $this->client->id, 'minimum_balance_cents' => 100_000]);
        $retainer = Matter::factory()->for($this->client)->create();
        $retainer->forceFill(['fee_arrangement' => 'retainer', 'fixed_fee_cents' => 3_000_000, 'retainer_auto_bill' => true, 'retainer_billing_day' => 15])->save();

        $this->getJson('/api/v1/collections')
            ->assertOk()
            ->assertJsonPath('reminders_enabled', true)
            ->assertJsonCount(1, 'invoices')
            ->assertJsonPath('invoices.0.number', $overdue->number)
            ->assertJsonPath('invoices.0.days_overdue', 10)
            ->assertJsonPath('invoices.0.next_reminder', 'A week overdue')
            ->assertJsonPath('trust_below_minimum.0.account_number', $account->account_number)
            ->assertJsonPath('retainers.0.next_billing', '2026-10-15');

        $this->signIn(Role::Associate, $this->firm);
        $this->getJson('/api/v1/collections')->assertForbidden();
    }
}
