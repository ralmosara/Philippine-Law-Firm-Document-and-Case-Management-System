<?php

namespace Database\Factories;

use App\Domain\Matters\Enums\MatterStatus;
use App\Domain\Matters\Models\Client;
use App\Domain\Matters\Models\Matter;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Matter>
 */
class MatterFactory extends Factory
{
    protected $model = Matter::class;

    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'firm_id' => fn (array $attributes) => Client::withoutGlobalScopes()->find($attributes['client_id'])->firm_id,
            'title' => fake()->lastName().' v. '.fake()->lastName(),
            'case_type' => fake()->randomElement(['Civil', 'Criminal', 'Labor', 'Family']),
            'court' => 'Regional Trial Court',
            'court_branch' => 'Branch '.fake()->numberBetween(1, 150).', Makati City',
            'opened_at' => now()->subDays(fake()->numberBetween(0, 365)),
        ];
    }

    /** Set a status directly (bypassing the transition action) for test fixtures. */
    public function status(MatterStatus $status): static
    {
        return $this->afterMaking(fn (Matter $matter) => $matter->status = $status);
    }
}
