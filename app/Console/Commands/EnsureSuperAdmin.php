<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Demo;
use App\Support\SuperAdmin;
use Illuminate\Console\Command;

/**
 * On-site recovery: put the built-in super_admin back (see App\Support\SuperAdmin).
 * The app does the same on every launch; this is for when it could not, or for a
 * dev database that was migrated without seeding.
 */
class EnsureSuperAdmin extends Command
{
    protected $signature = 'pos:ensure-super-admin';

    protected $description = 'Create the built-in super_admin from POS_SUPER_ADMIN_* if none exists';

    public function handle(): int
    {
        if ($user = SuperAdmin::ensure()) {
            $this->info("Created super_admin '{$user->username}'.");

            return self::SUCCESS;
        }

        if (Demo::enabled()) {
            $this->warn('Demo build — it carries no super_admin by design.');

            return self::SUCCESS;
        }

        if (User::query()->where('role', User::ROLE_SUPER_ADMIN)->exists()) {
            $this->info('A super_admin already exists — nothing to do.');

            return self::SUCCESS;
        }

        $this->error('No super_admin created: POS_SUPER_ADMIN_PASSWORD_HASH is missing or not a bcrypt hash, or the username belongs to another account. See the log.');

        return self::FAILURE;
    }
}
