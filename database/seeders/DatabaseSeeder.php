<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Config defaults plus the vendor's built-in super_admin, and nothing
        // else: no client account and no sample catalogue. The super_admin comes
        // from POS_SUPER_ADMIN_* (a bcrypt hash, no plaintext in the repo) and is
        // skipped in demo builds or when the hash is not configured. Last, so it
        // reads the language setting seeded above.
        $this->call([
            DefaultCurrenciesSeeder::class,
            DefaultTaxSeeder::class,
            DefaultSettingsSeeder::class,
            SuperAdminSeeder::class,
        ]);
    }
}
