<?php

namespace Database\Seeders;

use App\Support\FakeData;
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

        // Development and testing only: POS_FAKE_DATA=true, and never in production, in a demo build, or into a
        // database that already holds business data. enabled() and problem() are the whole of that rule
        // (App\Support\FakeData); ReleaseEnv also refuses to package a build with the flag on.
        if (FakeData::enabled() && FakeData::problem() === null) {
            $this->call(FakeDataSeeder::class);
        }
    }
}
