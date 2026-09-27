<?php

namespace Database\Factories;

use App\Domain\Matters\Models\Firm;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Firm>
 */
class FirmFactory extends Factory
{
    protected $model = Firm::class;

    public function definition(): array
    {
        return [
            'name' => fake()->lastName().' & '.fake()->lastName().' Law Offices',
            'tin' => fake()->numerify('###-###-###-000'),
            'address' => fake()->streetAddress().', Makati City',
            'email' => fake()->companyEmail(),
            'phone' => '+632'.fake()->numerify('8#######'),
            'vat_registered' => true,
        ];
    }
}
