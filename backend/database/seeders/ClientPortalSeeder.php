<?php

namespace Database\Seeders;

use App\Domain\Matters\Models\Client;
use Illuminate\Database\Seeder;

/**
 * Enables portal access (password: "password") for the demo firm's first
 * client. EnterpriseDemoSeeder already does this; run on its own to restore
 * the demo login after it has been changed.
 */
class ClientPortalSeeder extends Seeder
{
    public function run(): void
    {
        $client = Client::withoutGlobalScopes()->where('email', 'client1@corporate.com')->first();

        if ($client === null) {
            $this->command?->warn('Run EnterpriseDemoSeeder first.');

            return;
        }

        $client->forceFill(['portal_enabled' => true, 'password' => 'password'])->save();
        $this->command?->info('Portal login enabled for client1@corporate.com / password.');
    }
}
