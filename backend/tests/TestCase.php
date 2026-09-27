<?php

namespace Tests;

use App\Domain\Matters\Models\Firm;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Authenticate as a new staff member of the given role (and firm).
     */
    protected function signIn(Role $role = Role::Associate, ?Firm $firm = null, array $attributes = []): User
    {
        $user = User::factory()->role($role)->create([
            ...($firm ? ['firm_id' => $firm->id] : []),
            ...$attributes,
        ]);

        $this->actingAs($user);

        return $user;
    }
}
