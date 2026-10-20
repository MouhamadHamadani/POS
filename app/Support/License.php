<?php

namespace App\Support;

use App\Facades\Feature;
use App\Models\User;

/**
 * Which plan this install is licensed for, and its seat and role limits.
 *
 * A perpetual, single-device licence sold in three plans (config/features.php). There
 * is deliberately no key, no signature and no phone-home: the plan is a plain setting
 * only a super_admin can reach, set by whoever installs the machine. The honesty model
 * is the same as the price list.
 *
 * Which features apply is App\Services\FeatureService's job (plan preset + per-install
 * overrides + dependencies); `allows()` is kept as the old name for `Feature::enabled()`.
 * What stays here is what a plan limits rather than switches: seats, roles, devices.
 * Whether the install is still allowed to run at all (App\Support\Activation: trial /
 * activated / locked) is an independent setting; neither writes the other, and a locked
 * install is locked whatever the plan says — ActivationGate runs before any feature gate.
 *
 * Read-side is cheap — the resolved feature map is memoised per request and Setting is
 * cached — so gates can call these freely, and a plan change on the Plan tab takes
 * effect on the very next request.
 */
final class License
{
    public static function tier(): string
    {
        return Feature::plan();
    }

    public static function allows(string $feature): bool
    {
        return Feature::enabled($feature);
    }

    /** Roles this plan permits to exist at all. Never includes super_admin. */
    public static function allowedRoles(): array
    {
        return Feature::limits()['roles'] ?? [];
    }

    public static function maxUsers(): ?int
    {
        return Feature::limits()['max_users'] ?? null;
    }

    /**
     * Informational only. Multi-device (LAN host + terminal) is not built yet,
     * so nothing counts devices — this is the number the Plan tab displays.
     */
    public static function maxDevices(): ?int
    {
        return Feature::limits()['max_devices'] ?? null;
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
        return Feature::plans();
    }

    public static function setTier(string $tier, ?int $actorId = null): void
    {
        Feature::setPlan($tier, $actorId);
    }
}
