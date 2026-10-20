<?php

namespace Database\Seeders;

use App\Support\SuperAdmin;
use Illuminate\Database\Seeder;

class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        SuperAdmin::ensure();
    }
}
