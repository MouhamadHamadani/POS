<?php

use App\Models\AuditLog;
use App\Models\Setting;
use App\Support\License;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Write down what the code already assumes for an install that predates the
 * trial and tier features.
 *
 * Such an install has no `activation_status`, `installed_at` or `tier` row. The
 * code reads that as "activated, on the default tier", so nothing misbehaves —
 * but the state is invisible in the database, and a later change to either
 * default would silently change every old install. This makes the state
 * explicit, once.
 *
 * Each key is written only when its row is absent, so an existing value is never
 * touched and a second run writes nothing. A fresh database (no users yet) gets
 * no status and no tier: SetupController -> Activation::startTrial() still writes
 * `trial` there, which it only does for an unset status.
 *
 * Setting::set() clears each key's cache entry, so the cached "absent" markers go
 * with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        $hasUsers = DB::table('users')->exists();
        $written = [];

        if ($hasUsers && ! $this->has('activation_status')) {
            $written['activation_status'] = ['activated', 'activation'];
        }

        if (! $this->has('installed_at') && ($installedAt = $this->earliestRecord())) {
            $written['installed_at'] = [$installedAt->toIso8601String(), 'activation'];
        }

        // What License::tier() resolves to right now (the config default for an
        // old install), so behaviour is unchanged.
        if ($hasUsers && ! $this->has('tier')) {
            $written['tier'] = [License::tier(), 'license'];
        }

        foreach ($written as $key => [$value, $group]) {
            Setting::set($key, $value, $group);
        }

        if ($written) {
            AuditLog::record(null, 'settings_backfill', 'Setting', null, null, array_map(fn ($w) => $w[0], $written));
        }
    }

    /** Never deletes licence state on rollback: an activated install must stay activated. */
    public function down(): void
    {
        //
    }

    /** Straight from the table: the Setting cache may hold a stale "absent". */
    private function has(string $key): bool
    {
        return DB::table('settings')->where('key', $key)->exists();
    }

    /** The first sign of life: the earlier of the first audit-log row and the first account. */
    private function earliestRecord(): ?Carbon
    {
        return collect([DB::table('audit_logs')->min('created_at'), DB::table('users')->min('created_at')])
            ->filter()
            ->map(fn ($at) => Carbon::parse($at))
            ->sort()
            ->first();
    }
};
