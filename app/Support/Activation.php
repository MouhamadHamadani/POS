<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * The 14-day trial lock for this paid, perpetual-license build.
 *
 * A fresh install runs normally for TRIAL_DAYS from the moment setup finishes.
 * If the vendor has not marked it `activated` by then, `evaluate()` flips it to
 * `locked` and ActivationGate shows a lock screen until a super_admin unlocks it.
 * The vendor can also lock an install by hand (`deactivate()`). Locking is the
 * whole mechanism: nothing here deletes or alters business data.
 *
 * Everything is resolved locally from four settings (`installed_at`,
 * `activation_status`, `activation_lock_reason`, `activation_seen_at`) — no
 * network, no license server.
 * That also bounds what it can prove: it deters a non-technical client, it is
 * not DRM. Anyone who can open the SQLite file can write `activated` into it.
 * (A vendor-signed activation code would close that; see the ponytail note on
 * activate().)
 */
final class Activation
{
    public const TRIAL = 'trial';
    public const ACTIVATED = 'activated';
    public const LOCKED = 'locked';

    /** Why a locked install is locked (`activation_lock_reason`): the trial ran out, or the vendor paused it. */
    public const REASON_TRIAL_EXPIRED = 'trial_expired';
    public const REASON_MANUAL = 'manual';

    public const TRIAL_DAYS = 14;

    /** The "contact your provider" banner starts once this many days have passed. */
    public const WARN_FROM_DAY = 10;

    /** How far behind the last-seen time the clock may be before it counts as rolled back. */
    private const CLOCK_TOLERANCE = 86400;

    /** How often the last-seen high-water mark is rewritten. */
    private const SEEN_EVERY = 3600;

    /**
     * Unset means `activated`, on purpose: an install that predates this feature
     * has no row and must never start locking after an upgrade. Only
     * SetupController (via startTrial()) ever writes `trial`.
     */
    public static function status(): string
    {
        return (string) Setting::get('activation_status', self::ACTIVATED);
    }

    /** A demo build is never a paid install and never a trial; it does not lock. */
    public static function isTrial(): bool
    {
        return ! Demo::enabled() && self::status() === self::TRIAL;
    }

    public static function isActivated(): bool
    {
        return Demo::enabled() || self::status() === self::ACTIVATED;
    }

    /**
     * Locked once the trial has run out (persisted by evaluate()), or while the
     * system clock sits behind the last time the app ran. The second is not
     * stored: it clears the moment the date is corrected.
     */
    public static function isLocked(): bool
    {
        if (Demo::enabled()) {
            return false;
        }

        return match (self::status()) {
            self::LOCKED => true,
            self::TRIAL => self::clockRolledBack(),
            default => false,
        };
    }

    /**
     * Why the install is locked; null when it is not. A lock written before
     * reasons existed can only have been the trial running out, so a missing
     * reason on a locked install reads as that.
     */
    public static function lockReason(): ?string
    {
        if (self::status() !== self::LOCKED) {
            return null;
        }

        return Setting::get('activation_lock_reason') ?: self::REASON_TRIAL_EXPIRED;
    }

    /**
     * When the trial clock started. Falls back to the first audit-log row (the
     * setup entry) if the setting is missing, so deleting that one row cannot
     * restart the trial.
     */
    public static function installedAt(): Carbon
    {
        $raw = Setting::get('installed_at') ?: AuditLog::query()->min('created_at');

        return $raw ? Carbon::parse($raw) : now();
    }

    public static function expiresAt(): Carbon
    {
        return self::installedAt()->copy()->addDays(self::TRIAL_DAYS);
    }

    /** Whole days left, rounded up so "1" still means "until the end of today's window". */
    public static function daysRemaining(): int
    {
        $seconds = self::expiresAt()->getTimestamp() - now()->getTimestamp();

        return max(0, (int) ceil($seconds / 86400));
    }

    /** True once the pre-lock banner should show: day 10 onward, still unlocked. */
    public static function shouldWarn(): bool
    {
        return self::isTrial()
            && ! self::isLocked()
            && self::daysRemaining() <= self::TRIAL_DAYS - self::WARN_FROM_DAY;
    }

    /** Called once, by setup. Idempotent: an existing clock or status is left alone. */
    public static function startTrial(): void
    {
        if (Setting::get('installed_at') === null) {
            Setting::set('installed_at', now()->toIso8601String(), 'activation');
        }

        if (Setting::get('activation_status') === null) {
            Setting::set('activation_status', self::TRIAL, 'activation');
        }

        self::touchSeen();
    }

    /**
     * Run on every request by ActivationGate. Flips trial -> locked when the
     * window has passed, and keeps the clock high-water mark current.
     */
    public static function evaluate(): void
    {
        if (! self::isTrial()) {
            return;
        }

        if (Setting::get('installed_at') === null) {
            Setting::set('installed_at', self::installedAt()->toIso8601String(), 'activation');
        }

        self::touchSeen();

        if (now()->getTimestamp() >= self::expiresAt()->getTimestamp()) {
            Setting::set('activation_status', self::LOCKED, 'activation');
            Setting::set('activation_lock_reason', self::REASON_TRIAL_EXPIRED, 'activation');

            AuditLog::record(null, 'trial_expired', 'Setting', null,
                ['activation_status' => self::TRIAL],
                ['activation_status' => self::LOCKED],
            );
        }
    }

    /**
     * Unlock permanently. Route middleware already limits this to super_admin;
     * the check here keeps it true for any future caller too.
     *
     * ponytail: a plain status flip, so anyone with the SQLite file can fake it.
     * Upgrade path if that matters: the vendor signs a per-install code
     * (Ed25519, public key embedded) and this verifies it instead of trusting a flag.
     */
    public static function activate(User $actor): void
    {
        abort_unless($actor->isSuperAdmin(), 403);

        $was = self::status();

        if ($was === self::ACTIVATED) {
            return;
        }

        Setting::set('activation_status', self::ACTIVATED, 'activation');

        if (Setting::get('activation_lock_reason')) {
            Setting::set('activation_lock_reason', '', 'activation');
        }

        AuditLog::record($actor->id, 'activate', 'Setting', null,
            ['activation_status' => $was],
            ['activation_status' => self::ACTIVATED],
        );
    }

    /**
     * Lock the install on purpose (client stopped paying, or it shipped before
     * payment). The mirror of activate(): same super_admin check, same audit
     * trail, and like every lock it only closes the door — no row is touched.
     * Re-activating brings the till back exactly as it was left.
     */
    public static function deactivate(User $actor): void
    {
        abort_unless($actor->isSuperAdmin(), 403);

        $was = self::status();

        if ($was === self::LOCKED) {
            return;
        }

        Setting::set('activation_status', self::LOCKED, 'activation');
        Setting::set('activation_lock_reason', self::REASON_MANUAL, 'activation');

        AuditLog::record($actor->id, 'deactivate', 'Setting', null,
            ['activation_status' => $was],
            ['activation_status' => self::LOCKED],
        );
    }

    /**
     * The system clock reads earlier than the last time this app ran, by more
     * than the tolerance. Rolling the date back mid-trial would otherwise buy
     * time, so a trial install treats it as locked until the date is fixed.
     */
    public static function clockRolledBack(): bool
    {
        $seen = Setting::get('activation_seen_at');

        return $seen && now()->getTimestamp() < Carbon::parse($seen)->getTimestamp() - self::CLOCK_TOLERANCE;
    }

    /** High-water mark: only ever moves forward, so a rolled-back clock cannot lower it. */
    private static function touchSeen(): void
    {
        $seen = Setting::get('activation_seen_at');

        if (! $seen || now()->getTimestamp() - Carbon::parse($seen)->getTimestamp() >= self::SEEN_EVERY) {
            Setting::set('activation_seen_at', now()->toIso8601String(), 'activation');
        }
    }
}
