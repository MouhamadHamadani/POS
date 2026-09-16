<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Creates the first-run account only: the vendor/owner super_admin used to
 * provision the machine. The client's own `admin` (store owner) account is
 * created through User Management after first login — see INSTALL_NOTES.md.
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['username' => 'admin'],
            [
                'name' => 'System Owner',
                'email' => 'admin@pospro.local',
                'password' => Hash::make('admin123'),
                'role' => User::ROLE_SUPER_ADMIN,
                'pin' => Hash::make('1234'),
                'language' => 'en',
                'is_active' => true,
                'max_discount_pct' => 100,
                'email_verified_at' => now(),
            ]
        );
    }
}
