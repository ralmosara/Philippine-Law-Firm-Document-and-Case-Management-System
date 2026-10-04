<?php

namespace Tests\Feature\Billing;

use App\Domain\Billing\Models\Expense;
use App\Domain\Billing\Models\TimeEntry;
use App\Domain\EInvoicing\EInvoice;
use App\Domain\EInvoicing\EInvoicing;
use App\Domain\EInvoicing\Notifications\EInvoicesOverdue;
use App\Domain\Matters\Models\Client;
use App\Domain\Matters\Models\Firm;
use App\Domain\Matters\Models\Matter;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Tests\TestCase;

class EInvoicingTest extends TestCase
{
    use RefreshDatabase;

    private Firm $firm;

    private Matter $matter;

    private User $partner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->firm = Firm::factory()->create(['name' => 'Reyes & Cruz Law', 'tin' => '123-456-789-000', 'vat_registered' => true, 'address' => 'Makati City']);
        $client = Client::factory()->for($this->firm)->create(['name' => 'Kalayaan Realty Corp.', 'tin' => '987-654-321-000', 'address' => 'Pasig City', 'type' => 'corporate']);
        $this->matter = Matter::factory()->for($client)->create();
        $this->partner = $this->signIn(Role::ManagingPartner, $this->firm, ['hourly_rate_cents' => 500_000]);
    }

    private function issueInvoice(): int
    {
        TimeEntry::factory()->for($this->matter)->create(['user_id' => $this->partner->id, 'minutes' => 90, 'rate_cents' => 500_000, 'description' => 'Drafted the answer']);
        Expense::create(['firm_id' => $this->firm->id, 'matter_id' => $this->matter->id, 'user_id' => $this->partner->id, 'expense_date' => today()->toDateString(), 'category' => 'filing_fee', 'description' => 'Docket fees', 'amount_cents' => 2_000_00]);
        $id = $this->postJson('/api/v1/invoices', ['matter_id' => $this->matter->id])->assertCreated()->json('id');
        $this->postJson("/api/v1/invoices/{$id}/issue")->assertOk();

        return $id;
    }

    private function enable(): void
    {
        $this->putJson('/api/v1/e-invoicing/settings', ['einvoicing_enabled' => true, 'tin_branch_code' => '000'])
            ->assertOk()->assertJsonPath('settings.einvoicing_enabled', true)->assertJsonPath('settings.tin_branch_code', '00000');
    }

    public function test_nothing_is_created_while_e_invoicing_is_off(): void
    {
        $this->issueInvoice();
        $this->assertSame(0, EInvoice::count());
    }

    public function test_an_issued_invoice_becomes_an_e_invoice_with_what_the_bir_requires(): void
    {
        $this->enable();
        $id = $this->issueInvoice();

        $rows = $this->getJson("/api/v1/invoices/{$id}/e-invoices")->assertOk()->assertJsonPath('enabled', true)->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame('recorded', $rows[0]['status'], 'No provider yet: kept on file.');
        $this->assertSame(today()->addDays(3)->toDateString(), $rows[0]['due_on']);

        $payload = $this->getJson("/api/v1/e-invoices/{$rows[0]['id']}/payload")->assertOk()->json();
        $this->assertSame('invoice', $payload['document_type']);
        $this->assertSame(['registered_name' => 'Reyes & Cruz Law', 'tin' => '123456789000', 'branch_code' => '00000', 'address' => 'Makati City', 'vat_registered' => true], $payload['seller']);
        $this->assertSame('987654321000', $payload['buyer']['tin']);
        $this->assertSame([
            'vatable_sales' => '7500.00', 'vat_amount' => '900.00', 'non_vat_sales' => '0.00', 'vat_exempt_sales' => '0.00',
            'zero_rated_sales' => '0.00', 'reimbursable_expenses' => '2000.00', 'total_amount' => '10400.00',
        ], $payload['totals']);
        $this->assertStringEndsWith('Drafted the answer', $payload['lines'][0]['description']);
        $this->assertSame(['kind' => 'time', 'quantity' => '1.50', 'unit' => 'hour', 'unit_price' => '5000.00', 'amount' => '7500.00'], array_diff_key($payload['lines'][0], ['description' => true]));
        $this->assertSame(hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)), $rows[0]['payload_sha256']);
    }

    public function test_a_provider_endpoint_receives_it_signed_and_idempotent(): void
    {
        config(['services.einvoicing' => ['driver' => 'http', 'endpoint' => 'https://einvoice.example.ph/v1/documents', 'token' => 'tok', 'secret' => 's3cret', 'deadline_days' => 3]]);
        Http::fake(['einvoice.example.ph/*' => Http::response(['status' => 'accepted', 'reference' => 'EIS-2026-000123'])]);
        $this->enable();
        $id = $this->issueInvoice();

        $row = $this->getJson("/api/v1/invoices/{$id}/e-invoices")->json('data.0');
        $this->assertSame('accepted', $row['status']);
        $this->assertSame('EIS-2026-000123', $row['provider_reference']);
        Http::assertSent(fn (HttpRequest $r) => $r->hasHeader('Authorization', 'Bearer tok')
            && $r->hasHeader('Idempotency-Key', "lexph-einvoice-{$row['id']}")
            && $r->header('X-Signature')[0] === 'sha256='.hash_hmac('sha256', $r->body(), 's3cret')
            && $r['invoice_number'] !== null);
    }

    public function test_a_rejection_is_explained_and_sent_again_after_the_fix(): void
    {
        config(['services.einvoicing' => ['driver' => 'http', 'endpoint' => 'https://einvoice.example.ph/v1/documents', 'token' => 't', 'secret' => 's', 'deadline_days' => 3]]);
        Http::fakeSequence('einvoice.example.ph/*')
            ->push(['message' => 'Buyer TIN is not registered.'], 422)
            ->push(['status' => 'submitted', 'reference' => 'EIS-9'], 202);
        $this->enable();
        $id = $this->issueInvoice();

        $row = $this->getJson("/api/v1/invoices/{$id}/e-invoices")->json('data.0');
        $this->assertSame('rejected', $row['status']);
        $this->assertSame('Buyer TIN is not registered.', $row['error']);
        $this->assertTrue($row['can_retry']);

        $this->matter->client->forceFill(['tin' => '111-222-333-000'])->save();
        $this->postJson("/api/v1/e-invoices/{$row['id']}/retry")->assertOk();
        $row = $this->getJson("/api/v1/invoices/{$id}/e-invoices")->json('data.0');
        $this->assertSame('submitted', $row['status']);
        $this->assertSame(2, $row['attempts']);
        $this->assertSame('111222333000', EInvoice::find($row['id'])->payload['buyer']['tin'], 'Rebuilt from the corrected client.');
    }

    public function test_an_unreachable_provider_is_a_failure_to_retry(): void
    {
        config(['services.einvoicing' => ['driver' => 'http', 'endpoint' => 'https://einvoice.example.ph/v1/documents', 'token' => 't', 'secret' => 's', 'deadline_days' => 3]]);
        Http::fake(['einvoice.example.ph/*' => Http::response('Bad gateway', 502)]);
        $this->firm->forceFill(['einvoicing_enabled' => true])->save();
        $eInvoice = EInvoice::create(['firm_id' => $this->firm->id, 'invoice_id' => $this->createDraftInvoice(), 'kind' => 'invoice', 'driver' => 'http', 'payload' => ['x' => 1], 'payload_sha256' => str_repeat('a', 64), 'due_on' => today()->toDateString()]);

        try {
            app(EInvoicing::class)->transmit($eInvoice);
            $this->fail('A 502 should be retried.');
        } catch (RuntimeException) {
        }
        $this->assertSame('failed', $eInvoice->fresh()->status);
        $this->assertStringContainsString('HTTP 502', $eInvoice->fresh()->error);
    }

    public function test_voiding_cancels_what_was_sent_and_withdraws_what_was_not(): void
    {
        $this->enable();
        $sent = $this->issueInvoice();
        $this->postJson("/api/v1/invoices/{$sent}/void")->assertOk();
        $rows = $this->getJson("/api/v1/invoices/{$sent}/e-invoices")->json('data');
        $this->assertSame(['invoice', 'cancellation'], array_column($rows, 'kind'));
        $this->assertSame('cancellation', EInvoice::find($rows[1]['id'])->payload['document_type']);

        // Never reached the BIR: nothing to cancel.
        $pending = $this->createDraftInvoice();
        $this->postJson("/api/v1/invoices/{$pending}/issue")->assertOk();
        EInvoice::where('invoice_id', $pending)->update(['status' => 'failed']);
        $this->postJson("/api/v1/invoices/{$pending}/void")->assertOk();
        $this->assertSame(['withdrawn'], EInvoice::where('invoice_id', $pending)->pluck('status')->all());
    }

    public function test_e_invoices_not_sent_by_their_due_date_are_flagged_once(): void
    {
        Notification::fake();
        $this->firm->forceFill(['einvoicing_enabled' => true])->save();
        $invoiceId = $this->createDraftInvoice();
        EInvoice::create(['firm_id' => $this->firm->id, 'invoice_id' => $invoiceId, 'kind' => 'invoice', 'driver' => 'http', 'payload' => [], 'payload_sha256' => str_repeat('b', 64), 'due_on' => today()->toDateString()])->forceFill(['status' => 'failed'])->save();

        $this->artisan('einvoices:flag-overdue')->assertSuccessful();
        Notification::assertSentTo($this->partner, EInvoicesOverdue::class);
        $this->artisan('einvoices:flag-overdue')->assertSuccessful();
        Notification::assertSentTimes(EInvoicesOverdue::class, 1);
    }

    public function test_settings_need_a_tin_and_the_right_role(): void
    {
        $this->firm->forceFill(['tin' => null])->save();
        $this->putJson('/api/v1/e-invoicing/settings', ['einvoicing_enabled' => true, 'tin_branch_code' => '00000'])->assertStatus(422)->assertJsonValidationErrors('einvoicing_enabled');
        $this->putJson('/api/v1/e-invoicing/settings', ['einvoicing_enabled' => false, 'tin_branch_code' => 'abc'])->assertStatus(422)->assertJsonValidationErrors('tin_branch_code');

        $this->signIn(Role::Associate, $this->firm);
        $this->putJson('/api/v1/e-invoicing/settings', ['einvoicing_enabled' => false, 'tin_branch_code' => '00000'])->assertForbidden();
        $this->getJson('/api/v1/e-invoicing')->assertForbidden();
    }

    public function test_a_plain_http_endpoint_is_refused(): void
    {
        config(['services.einvoicing' => ['driver' => 'http', 'endpoint' => 'http://einvoice.example.ph', 'token' => 't', 'secret' => 's', 'deadline_days' => 3]]);
        $this->getJson('/api/v1/e-invoicing')->assertOk()
            ->assertJsonPath('settings.ready', false)
            ->assertJsonPath('settings.problem', 'The e-invoicing endpoint must use HTTPS.');
    }

    private function createDraftInvoice(): int
    {
        TimeEntry::factory()->for($this->matter)->create(['user_id' => $this->partner->id, 'minutes' => 60, 'rate_cents' => 100_000]);

        return $this->postJson('/api/v1/invoices', ['matter_id' => $this->matter->id])->assertCreated()->json('id');
    }
}
