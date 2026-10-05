<?php

namespace Tests\Feature\Billing;

use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\TimeEntry;
use App\Domain\Billing\Services\InvoiceGenerator;
use App\Domain\Billing\Services\InvoicePayments;
use App\Domain\Billing\Statements\ClientStatements;
use App\Domain\Billing\Statements\Notifications\StatementOfAccountSent;
use App\Domain\Billing\Statements\StatementOfAccount;
use App\Domain\Matters\Models\Client;
use App\Domain\Matters\Models\Firm;
use App\Domain\Matters\Models\Matter;
use App\Domain\Trust\Models\TrustAccount;
use App\Domain\Trust\Services\TrustLedgerService;
use App\Enums\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class StatementsTest extends TestCase
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
        $this->firm = Firm::factory()->create();
        $this->client = Client::factory()->for($this->firm)->withPortal('secret-pass')->create(['email' => 'accounts@client.ph', 'locale' => 'fil']);
        $this->partner = $this->signIn(Role::ManagingPartner, $this->firm);
    }

    private function invoice(int $hours, string $dueAt): Invoice
    {
        $matter = Matter::factory()->for($this->client)->create();
        TimeEntry::factory()->for($matter)->create(['user_id' => $this->partner->id, 'minutes' => 60 * $hours, 'rate_cents' => 100_000]);
        $generator = app(InvoiceGenerator::class);
        $invoice = $generator->issue($generator->generateForMatter($matter, $this->partner));
        $invoice->forceFill(['due_at' => $dueAt])->save();

        return $invoice->fresh();
    }

    public function test_open_invoices_are_aged_from_their_due_date(): void
    {
        $current = $this->invoice(1, '2026-10-20');
        $late = $this->invoice(2, '2026-09-15');      // 16 days overdue
        $older = $this->invoice(3, '2026-07-01');     // 92 days overdue
        app(InvoicePayments::class)->record($late, ['method' => 'bank_transfer', 'amount_cents' => 50_000, 'received_on' => '2026-09-20'], $this->partner);

        $s = app(StatementOfAccount::class)->build($this->client);

        $this->assertSame($current->total_cents, $s['aging']['current']);
        $this->assertSame($late->total_cents - 50_000, $s['aging']['1_30']);
        $this->assertSame($older->total_cents, $s['aging']['over_90']);
        $this->assertSame($current->total_cents + $late->total_cents - 50_000 + $older->total_cents, $s['total_due']);
        $this->assertSame([$older->number, $late->number, $current->number], array_column($s['invoices'], 'number'));
        $this->assertSame(50_000, $s['received']);
        $this->assertTrue(app(StatementOfAccount::class)->hasContent($s));
    }

    public function test_trust_funds_and_their_movements_are_included(): void
    {
        $account = TrustAccount::factory()->for($this->firm)->create(['client_id' => $this->client->id]);
        app(TrustLedgerService::class)->deposit($account, 2_000_000, 'Advance for filing fees', by: $this->partner);
        app(TrustLedgerService::class)->disburse($account, 300_000, 'Docket fees', by: $this->partner);

        $s = app(StatementOfAccount::class)->build($this->client);

        $this->assertSame(0, $s['total_due']);
        $this->assertSame(1_700_000, $s['trust_total']);
        $this->assertSame(['Advance for filing fees', 'Docket fees'], array_column($s['trust'][0]['transactions'], 'description'));
        $this->assertTrue(app(StatementOfAccount::class)->hasContent($s), 'Funds in trust are worth a statement on their own.');
    }

    public function test_the_pdf_downloads_for_staff_and_the_client(): void
    {
        $this->invoice(1, '2026-09-01');

        $this->get("/api/v1/clients/{$this->client->id}/statement/pdf")->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->getJson("/api/v1/clients/{$this->client->id}/statement")->assertOk()->assertJsonPath('open_invoices', 1)->assertJsonPath('has_content', true);

        $this->actingAs($this->client, 'client');
        $this->get('/api/portal/statement/pdf')->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    public function test_emailing_one_statement_needs_finance_rights_and_something_to_send(): void
    {
        $this->postJson("/api/v1/clients/{$this->client->id}/statement/send")->assertUnprocessable();
        Notification::assertNothingSent();

        $this->invoice(1, '2026-09-01');
        $this->postJson("/api/v1/clients/{$this->client->id}/statement/send")->assertOk();
        Notification::assertSentTo($this->client, StatementOfAccountSent::class);
        $this->assertSame('2026-10-01', $this->client->fresh()->statement_sent_on->toDateString());

        $this->signIn(Role::Associate, $this->firm);
        $this->postJson("/api/v1/clients/{$this->client->id}/statement/send")->assertForbidden();
    }

    public function test_the_email_is_in_the_clients_language_with_the_pdf(): void
    {
        $this->invoice(1, '2026-09-01');
        app(ClientStatements::class)->send($this->client);

        Notification::assertSentTo($this->client, StatementOfAccountSent::class, function (StatementOfAccountSent $n) {
            app()->setLocale($this->client->preferredLocale());
            $mail = $n->toMail($this->client);
            app()->setLocale('en');

            return str_starts_with($mail->subject, 'Statement of account hanggang')
                && count($mail->rawAttachments) === 1
                && str_starts_with($mail->rawAttachments[0]['data'], '%PDF');
        });
    }

    public function test_monthly_run_on_the_statement_day_sends_once_and_skips_clients_with_nothing(): void
    {
        $this->firm->update(['statements_enabled' => true, 'statement_day' => 5]);
        $this->invoice(1, '2026-09-01');
        $settled = Client::factory()->for($this->firm)->create(['email' => 'paid@client.ph']);
        $noEmail = Client::factory()->for($this->firm)->create(['email' => null]);
        $other = Firm::factory()->create(['statements_enabled' => false]);
        $otherClient = Client::factory()->for($other)->create(['email' => 'x@other.ph']);
        Matter::factory()->for($otherClient)->create();

        $statements = app(ClientStatements::class);
        $this->assertSame(0, $statements->sendDue(CarbonImmutable::parse('2026-10-04')), 'Not yet the statement day.');
        $this->assertSame(1, $statements->sendDue(CarbonImmutable::parse('2026-10-05')));
        $this->assertSame(0, $statements->sendDue(CarbonImmutable::parse('2026-10-06')), 'Once a month.');

        Notification::assertSentToTimes($this->client, StatementOfAccountSent::class, 1);
        Notification::assertNotSentTo([$settled, $noEmail, $otherClient], StatementOfAccountSent::class);

        $this->assertSame(1, $statements->sendDue(CarbonImmutable::parse('2026-11-05')), 'Again the next month.');
    }

    public function test_send_now_covers_every_client_not_yet_sent_this_month(): void
    {
        $this->invoice(1, '2026-09-01');
        $second = Client::factory()->for($this->firm)->create(['email' => 'second@client.ph']);
        $account = TrustAccount::factory()->for($this->firm)->create(['client_id' => $second->id]);
        app(TrustLedgerService::class)->deposit($account, 500_000, 'Acceptance', by: $this->partner);

        $this->postJson('/api/v1/statements/send')->assertOk()->assertJsonPath('sent', 2);
        $this->postJson('/api/v1/statements/send')->assertOk()->assertJsonPath('sent', 0);
    }
}
