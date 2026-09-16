<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Config defaults only. No user is seeded by design — a shipped build
        // must not contain a known credential, so the owner account is created
        // on the machine through the /setup screen instead.
        $this->call([
            DefaultCurrenciesSeeder::class,
            DefaultTaxSeeder::class,
            DefaultSettingsSeeder::class,
        ]);
    }
}
