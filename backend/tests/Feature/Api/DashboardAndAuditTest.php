<?php

namespace Tests\Feature\Api;

use App\Domain\Billing\Models\TimeEntry;
use App\Domain\Billing\Services\InvoiceGenerator;
use App\Domain\Deadlines\Models\MatterDeadline;
use App\Domain\Matters\Enums\MatterStatus;
use App\Domain\Matters\Models\Client;
use App\Domain\Matters\Models\Firm;
use App\Domain\Matters\Models\Matter;
use App\Domain\Trust\Models\TrustAccount;
use App\Domain\Trust\Services\TrustLedgerService;
use App\Enums\Role;
use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardAndAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_dashboard_reports_real_figures_for_the_firm_only(): void
    {
        $firm = Firm::factory()->create();
        $partner = $this->signIn(Role::ManagingPartner, $firm);
        $matter = Matter::factory()->for(Client::factory()->for($firm))->create(['responsible_lawyer_id' => $partner->id]);
        Matter::factory()->for(Client::factory()->for($firm))->status(MatterStatus::Closed)->create();
        Matter::factory()->count(3)->create(); // other firms: must not be counted

        TimeEntry::factory()->for($matter)->create(['user_id' => $partner->id, 'minutes' => 60, 'rate_cents' => 100_000, 'work_date' => today()]);
        $invoices = app(InvoiceGenerator::class);
        $invoice = $invoices->issue($invoices->generateForMatter($matter, $partner));
        $invoices->markPaid($invoice, $partner, 'BANK-1');
        TimeEntry::factory()->for($matter)->create(['user_id' => $partner->id, 'minutes' => 30, 'rate_cents' => 100_000, 'work_date' => today()]);
        MatterDeadline::factory()->for($matter)->create(['due_date' => today()->addDays(2)]);
        app(TrustLedgerService::class)->deposit(TrustAccount::factory()->create(['client_id' => $matter->client_id]), 250_000, 'Deposit');

        $this->getJson('/api/v1/analytics/dashboard')
            ->assertOk()
            ->assertJsonPath('metrics.active_matters', 1)
            ->assertJsonPath('metrics.revenue_collected_ytd_cents', 112_000)
            ->assertJsonPath('metrics.collection_rate', 100)
            ->assertJsonPath('metrics.unbilled_wip_cents', 50_000)
            ->assertJsonPath('metrics.trust_funds_held_cents', 250_000)
            ->assertJsonPath('metrics.deadlines_next_7_days', 1)
            ->assertJsonPath('utilization.0.billable_minutes', 90)
            ->assertJsonCount(6, 'revenue_trend')
            ->assertJsonPath('revenue_trend.5.collected_cents', 112_000);
    }

    public function test_changes_to_client_data_are_audited_without_secrets(): void
    {
        $user = $this->signIn(Role::ManagingPartner);
        $client = Client::factory()->create(['firm_id' => $user->firm_id, 'name' => 'Before']);

        $this->putJson("/api/v1/clients/{$client->id}", ['name' => 'After'])->assertOk();
        $this->putJson("/api/v1/clients/{$client->id}/portal-access", ['portal_enabled' => true, 'password' => 'super-secret-1'])->assertOk();

        $update = AuditLog::where('subject_id', $client->id)->where('action', 'updated')->first();
        $this->assertSame(['name' => 'Before'], $update->changes['before']);
        $this->assertSame(['name' => 'After'], $update->changes['after']);
        $this->assertEquals($user->id, $update->actor_id);

        $this->assertStringNotContainsString('super-secret', AuditLog::all()->toJson());

        $this->getJson('/api/v1/audit-logs?subject_type=client')->assertOk()->assertJsonPath('data.0.actor.name', $user->name);
    }

    public function test_every_paginated_endpoint_uses_the_same_envelope(): void
    {
        $this->signIn(Role::ManagingPartner);

        foreach (['users', 'clients', 'matters', 'documents', 'time-entries', 'invoices', 'trust-accounts', 'conflict-checks', 'notarial-entries', 'audit-logs'] as $endpoint) {
            $this->getJson("/api/v1/{$endpoint}?per_page=500")
                ->assertOk()
                ->assertJsonStructure(['data', 'links' => ['next', 'prev'], 'meta' => ['current_page', 'last_page', 'per_page', 'total']])
                ->assertJsonPath('meta.per_page', 100); // clamped
        }
    }

    public function test_unauthenticated_api_calls_get_a_401_envelope_even_without_a_json_accept_header(): void
    {
        foreach (['/api/v1/matters/1', '/api/v1/files/1/download', '/api/portal/matters'] as $url) {
            $this->get($url)
                ->assertUnauthorized()
                ->assertExactJson(['status' => 'error', 'message' => 'Unauthenticated.']);
        }
    }
}
