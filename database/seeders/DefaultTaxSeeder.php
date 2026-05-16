<?php

namespace Database\Seeders;

use App\Models\Tax;
use Illuminate\Database\Seeder;

class DefaultTaxSeeder extends Seeder
{
    public function run(): void
    {
        Tax::updateOrCreate(
            ['name' => 'Lebanese VAT'],
            [
                'name_ar' => 'ضريبة القيمة المضافة',
                'rate' => 0.11,
                'is_inclusive' => false,
                'is_default' => true,
                'is_active' => true,
                'description' => 'Lebanese Value Added Tax (VAT) at 11%',
            ]
        );

        Tax::updateOrCreate(
            ['name' => 'No Tax'],
            [
                'name_ar' => 'بدون ضريبة',
                'rate' => 0,
                'is_inclusive' => false,
                'is_default' => false,
                'is_active' => true,
                'description' => 'Tax-exempt items',
            ]
        );
    }
}
