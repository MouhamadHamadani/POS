<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use InvalidArgumentException;

/**
 * Which tier this install is licensed for, and what that tier includes.
 *
 * A perpetual, single-device licence sold in three tiers (config/pos.tiers).
 * There is deliberately no key, no signature and no phone-home: the tier is a
 * plain setting only a super_admin can reach, set by whoever installs the
 * machine. The honesty model is the same as the price list.
 *
 * Which features apply (this class) and whether the install is still allowed to
 * run at all (App\Support\Activation: trial / activated / locked) are two
 * independent settings. Neither writes the other, and a locked install is
 * locked whatever the tier says — ActivationGate runs before any feature gate.
 *
 * Read-side is cheap — Setting::get() is cached forever and invalidated on
 * write — so gates can call allows() per request without a cache of their own,
 * and a tier change on the Plan tab takes effect on the very next request.
 */
final class License
{
    public static function tier(): string
    {
        $tier = (string) Setting::get('tier', config('pos.default_tier'));

        // A hand-edited or half-migrated database must not lock the till down.
        return config("pos.tiers.{$tier}") ? $tier : (string) config('pos.default_tier');
    }

    /** @return array{max_users:?int,max_devices:?int,roles:array<int,string>,features:array<int,string>} */
    public static function config(): array
    {
        return config('pos.tiers.'.self::tier(), []);
    }

    public static function allows(string $feature): bool
    {
        return in_array($feature, self::config()['features'] ?? [], true);
    }

    /** Roles this tier permits to exist at all. Never includes super_admin. */
    public static function allowedRoles(): array
    {
        return self::config()['roles'] ?? [];
    }

    public static function maxUsers(): ?int
    {
        return self::config()['max_users'] ?? null;
    }

    /**
     * Informational only. Multi-device (LAN host + terminal) is not built yet,
     * so nothing counts devices — this is the number the Plan tab displays.
     */
    public static function maxDevices(): ?int
    {
        return self::config()['max_devices'] ?? null;
    }

    /**
     * Seats consumed. super_admin is the vendor's own support account and is
     * excluded here, once, so no caller has to remember to special-case it.
     */
    public static function seatsUsed(): int
    {
        return User::query()->where('role', '!=', User::ROLE_SUPER_ADMIN)->count();
    }

    public static function isAtUserLimit(): bool
    {
        $max = self::maxUsers();

        return $max !== null && self::seatsUsed() >= $max;
    }

    public static function tiers(): array
    {
        return array_keys(config('pos.tiers', []));
    }

    public static function setTier(string $tier, ?int $actorId = null): void
    {
        if (! in_array($tier, self::tiers(), true)) {
            throw new InvalidArgumentException("Unknown tier '{$tier}'.");
        }

        $old = self::tier();
        Setting::set('tier', $tier, 'license');

        AuditLog::record($actorId, 'tier_change', null, null, ['tier' => $old], ['tier' => $tier]);
    }
}
