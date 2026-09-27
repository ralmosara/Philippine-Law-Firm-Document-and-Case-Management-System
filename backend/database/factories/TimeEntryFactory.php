<?php

namespace Database\Factories;

use App\Domain\Billing\Models\TimeEntry;
use App\Domain\Matters\Models\Matter;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TimeEntry>
 */
class TimeEntryFactory extends Factory
{
    protected $model = TimeEntry::class;

    public function definition(): array
    {
        return [
            'matter_id' => Matter::factory(),
            'firm_id' => fn (array $attributes) => Matter::withoutGlobalScopes()->find($attributes['matter_id'])->firm_id,
            'user_id' => fn (array $attributes) => User::factory()->create(['firm_id' => $attributes['firm_id']])->id,
            'work_date' => now()->subDays(fake()->numberBetween(0, 20)),
            'minutes' => fake()->randomElement([30, 60, 90, 120]),
            'rate_cents' => 350000,
            'description' => fake()->randomElement(['Drafted answer', 'Conference with client', 'Legal research', 'Attended hearing']),
            'is_billable' => true,
        ];
    }
}
