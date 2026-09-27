<?php

namespace Database\Factories;

use App\Domain\Matters\Models\Client;
use App\Domain\Matters\Models\Firm;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Client>
 */
class ClientFactory extends Factory
{
    protected $model = Client::class;

    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'type' => 'individual',
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => '09'.fake()->numerify('#########'),
            'address' => fake()->streetAddress().', Quezon City',
        ];
    }

    public function corporate(): static
    {
        return $this->state(fn () => ['type' => 'corporate', 'name' => fake()->company().', Inc.']);
    }

    public function withPortal(string $password = 'password'): static
    {
        return $this->state(fn () => ['portal_enabled' => true, 'password' => $password]);
    }
}
