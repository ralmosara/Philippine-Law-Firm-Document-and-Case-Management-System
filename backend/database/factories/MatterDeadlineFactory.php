<?php

namespace Database\Factories;

use App\Domain\Deadlines\Enums\DeadlineKind;
use App\Domain\Deadlines\Models\MatterDeadline;
use App\Domain\Matters\Models\Matter;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MatterDeadline>
 */
class MatterDeadlineFactory extends Factory
{
    protected $model = MatterDeadline::class;

    public function definition(): array
    {
        return [
            'matter_id' => Matter::factory(),
            'firm_id' => fn (array $attributes) => Matter::withoutGlobalScopes()->find($attributes['matter_id'])->firm_id,
            'kind' => DeadlineKind::Filing,
            'title' => fake()->randomElement(['File Answer', 'File Pre-Trial Brief', 'File Memorandum', 'File Notice of Appeal']),
            'due_date' => now()->addDays(fake()->numberBetween(1, 30)),
        ];
    }

    public function hearing(): static
    {
        return $this->state(fn () => ['kind' => DeadlineKind::Hearing, 'title' => 'Hearing', 'due_time' => '08:30', 'location' => 'Sala of RTC Branch 12']);
    }
}
