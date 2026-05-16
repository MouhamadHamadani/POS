<?php

namespace Database\Seeders;

use App\Models\Currency;
use Illuminate\Database\Seeder;

class DefaultCurrenciesSeeder extends Seeder
{
    public function run(): void
    {
        Currency::updateOrCreate(['code' => 'USD'], [
            'name' => 'US Dollar',
            'symbol' => '$',
            'rate' => 1,
            'is_base' => true,
            'decimal_places' => 2,
            'rounding_step' => 0.01,
        ]);

        Currency::updateOrCreate(['code' => 'LBP'], [
            'name' => 'Lebanese Pound',
            'symbol' => 'L.L.',
            'rate' => 90000,
            'is_base' => false,
            'decimal_places' => 0,
            'rounding_step' => 1000,
        ]);
    }
}
