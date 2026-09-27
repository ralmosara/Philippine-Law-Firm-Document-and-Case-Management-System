<?php

namespace Tests\Feature\Api;

use App\Domain\Matters\Models\Client;
use App\Domain\Matters\Models\Firm;
use App\Domain\Matters\Models\Matter;
use App\Enums\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_standardized_404_for_missing_client(): void
    {
        $this->signIn();

        $this->getJson('/api/v1/clients/99999')
            ->assertNotFound()
            ->assertExactJson(['status' => 'error', 'message' => 'Resource not found.']);
    }

    public function test_clients_can_be_created_listed_and_searched(): void
    {
        $user = $this->signIn(Role::Paralegal);

        $this->postJson('/api/v1/clients', [
            'type' => 'corporate', 'name' => 'Acme Philippines, Inc.', 'email' => 'legal@acme.ph', 'tin' => '123-456-789-000',
        ])->assertCreated()->assertJsonPath('portal_enabled', false);

        $this->assertDatabaseHas('clients', ['name' => 'Acme Philippines, Inc.', 'firm_id' => $user->firm_id]);
        $this->getJson('/api/v1/clients?search=acme')->assertJsonCount(1, 'data')->assertJsonPath('data.0.matters_count', 0);
    }

    public function test_validation_errors_use_the_error_envelope(): void
    {
        $this->signIn();

        $this->postJson('/api/v1/clients', ['type' => 'alien', 'tin' => 'abc'])
            ->assertStatus(422)
            ->assertJsonPath('status', 'error')
            ->assertJsonValidationErrors(['name', 'type', 'tin']);
    }

    public function test_emails_are_unique_within_a_firm_only(): void
    {
        $user = $this->signIn();
        Client::factory()->for(Firm::find($user->firm_id))->create(['email' => 'same@example.com']);
        Client::factory()->for(Firm::factory())->create(['email' => 'other@example.com']);

        $this->postJson('/api/v1/clients', ['type' => 'individual', 'name' => 'Dup', 'email' => 'same@example.com'])
            ->assertStatus(422)->assertJsonValidationErrors('email');
        $this->postJson('/api/v1/clients', ['type' => 'individual', 'name' => 'Fine', 'email' => 'other@example.com'])
            ->assertCreated();
    }

    public function test_clients_with_matters_cannot_be_deleted(): void
    {
        $user = $this->signIn(Role::Partner);
        $client = Client::factory()->create(['firm_id' => $user->firm_id]);
        Matter::factory()->for($client)->create();

        $this->deleteJson("/api/v1/clients/{$client->id}")->assertStatus(422);
    }

    public function test_portal_access_requires_an_email_and_a_password(): void
    {
        $user = $this->signIn(Role::Associate);
        $client = Client::factory()->create(['firm_id' => $user->firm_id, 'email' => null]);

        $this->putJson("/api/v1/clients/{$client->id}/portal-access", ['portal_enabled' => true, 'password' => 'long-enough-1'])
            ->assertStatus(422);

        $client->update(['email' => 'c@example.com']);
        $this->putJson("/api/v1/clients/{$client->id}/portal-access", ['portal_enabled' => true])
            ->assertStatus(422)->assertJsonValidationErrors('password');
        $this->putJson("/api/v1/clients/{$client->id}/portal-access", ['portal_enabled' => true, 'password' => 'long-enough-1'])
            ->assertOk()->assertJsonPath('portal_enabled', true)->assertJsonMissingPath('password');
    }
}
