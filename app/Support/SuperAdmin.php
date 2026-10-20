<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * The vendor's own `super_admin` account, built into every install.
 *
 * Username and name come from config/pos.php (POS_SUPER_ADMIN_*), the password
 * as a bcrypt *hash* (POS_SUPER_ADMIN_PASSWORD_HASH) — the plain password is
 * never in the repo, the .env or the installer. The client's own `admin` is a
 * different account, created later by this one from Users → New User.
 *
 * ensure() is idempotent and safe to call on every launch: it creates the
 * account only while no super_admin exists, so a password the vendor changed
 * from inside the app is never reset, and an existing install is untouched.
 */
final class SuperAdmin
{
    /**
     * Create the super_admin if there is none. Returns the new user, or null when
     * nothing was created — demo build, one already exists, or the config is
     * unusable (logged as an error, never papered over with a fallback password).
     */
    public static function ensure(): ?User
    {
        // A demo ships known accounts and deliberately none of ours.
        if (Demo::enabled() || User::query()->where('role', User::ROLE_SUPER_ADMIN)->exists()) {
            return null;
        }

        $username = trim((string) config('pos.super_admin.username'));
        $hash = (string) config('pos.super_admin.password_hash');

        // Not "any non-empty string": a plain password pasted where the hash
        // belongs must not become a working credential sitting in the .env.
        if ($username === '' || (password_get_info($hash)['algoName'] ?? '') !== 'bcrypt') {
            Log::error('Super admin not provisioned: POS_SUPER_ADMIN_USERNAME is empty or POS_SUPER_ADMIN_PASSWORD_HASH is missing / not a bcrypt hash.');

            return null;
        }

        // No super_admin exists here, so whoever holds this username is a client
        // account. Never take it over.
        if (User::query()->where('username', $username)->exists()) {
            Log::error("Super admin not provisioned: the username '{$username}' already belongs to another account.");

            return null;
        }

        $language = (string) Setting::get('language', 'en');

        $user = new User([
            'name' => trim((string) config('pos.super_admin.name')) ?: $username,
            'username' => $username,
            'role' => User::ROLE_SUPER_ADMIN,
            'language' => in_array($language, ['en', 'ar'], true) ? $language : 'en',
            'is_active' => true,
            'max_discount_pct' => 100,
        ]);
        $user->forceFill(['email_verified_at' => now()]);

        // User casts `password` as `hashed`, and that cast runs in setAttribute():
        // it would re-hash a plain value, and for an already-hashed one it throws
        // when the hash's bcrypt cost differs from this machine's BCRYPT_ROUNDS.
        // setRawAttributes() skips setAttribute(), so the stored value is exactly
        // the configured hash.
        $user->setRawAttributes($user->getAttributes() + ['password' => $hash]);
        $user->save();

        // No authenticated actor exists yet, so the new account is its own actor.
        AuditLog::record($user->id, 'super_admin_provisioned', User::class, $user->id, null, [
            'username' => $user->username,
            'role' => $user->role,
        ]);

        // Under-provision, never over-provision: until the vendor picks the real
        // tier on the Plan tab a fresh install is Starter. Written down because
        // an unset tier reads as config('pos.default_tier') — `premium`, kept so
        // installs that predate tiers stay fully unlocked.
        if (Setting::get('tier') === null) {
            License::setTier('starter', $user->id);
        }

        return $user;
    }

    /**
     * A non-demo machine with no accounts at all: nobody can sign in, so the
     * login page says so instead of leaving a form that can never succeed.
     */
    public static function unprovisioned(): bool
    {
        return ! Demo::enabled() && ! User::query()->exists();
    }
}
