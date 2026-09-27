<?php

namespace Database\Factories;

use App\Domain\Matters\Models\Client;
use App\Domain\Trust\Models\TrustAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrustAccount>
 */
class TrustAccountFactory extends Factory
{
    protected $model = TrustAccount::class;

    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'firm_id' => fn (array $attributes) => Client::withoutGlobalScopes()->find($attributes['client_id'])->firm_id,
        ];
    }
}
