<?php

namespace Database\Factories;

use App\Domain\Matters\Models\Firm;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'name' => 'Atty. '.fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'role' => Role::Associate,
            'roll_number' => (string) fake()->numberBetween(40000, 89999),
            'ibp_number' => (string) fake()->numberBetween(100000, 999999),
            'hourly_rate_cents' => 350000,
            'is_active' => true,
        ];
    }

    public function role(Role $role): static
    {
        return $this->state(fn () => ['role' => $role]);
    }

    public function managingPartner(): static
    {
        return $this->role(Role::ManagingPartner);
    }

    public function partner(): static
    {
        return $this->role(Role::Partner);
    }

    public function paralegal(): static
    {
        return $this->role(Role::Paralegal)->state(fn () => ['roll_number' => null, 'ibp_number' => null, 'hourly_rate_cents' => 150000]);
    }

    public function staff(): static
    {
        return $this->role(Role::Staff)->state(fn () => ['roll_number' => null, 'ibp_number' => null, 'hourly_rate_cents' => 0]);
    }

    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => ['firm_id' => $firm->id]);
    }

    public function unverified(): static
    {
        return $this->state(fn () => ['email_verified_at' => null]);
    }
}
