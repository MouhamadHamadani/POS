<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['username' => 'admin'],
            [
                'name' => 'Administrator',
                'email' => 'admin@pospro.local',
                'password' => Hash::make('admin123'),
                'role' => User::ROLE_ADMIN,
                'pin' => Hash::make('1234'),
                'language' => 'en',
                'is_active' => true,
                'max_discount_pct' => 100,
                'email_verified_at' => now(),
            ]
        );
    }
}
