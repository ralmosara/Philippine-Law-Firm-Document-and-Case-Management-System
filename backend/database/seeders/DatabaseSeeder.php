<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Reference data always; demo firm data outside production only.
     */
    public function run(): void
    {
        $this->call(ReferenceDataSeeder::class);

        if (! app()->isProduction()) {
            $this->call(EnterpriseDemoSeeder::class);
        }
    }
}
