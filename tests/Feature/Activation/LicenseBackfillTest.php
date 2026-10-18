<?php

namespace Tests\Feature\Activation;

use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use App\Support\Activation;
use App\Support\License;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * An install that predates the trial and tier features has no `activation_status`,
 * `installed_at` or `tier` row. The code already treats that as "activated, on the
 * default tier"; the backfill migration writes it down so the state is visible and
 * a later change to a default cannot move old installs. It must never touch an
 * install that already has the value, and never switch a fresh install's trial off.
 */
class LicenseBackfillTest extends TestCase
{
    use RefreshDatabase;

    private const KEYS = ['activation_status', 'installed_at', 'tier'];

    /** Run the migration's up() the way NativePHP does at start-up. */
    private function backfill(): void
    {
        (require database_path('migrations/2026_10_18_000001_backfill_license_settings.php'))->up();
    }

    private function row(string $key): ?Setting
    {
        return Setting::query()->where('key', $key)->first();
    }

    public function test_an_old_install_gets_its_licence_state_written_down(): void
    {
        AuditLog::create(['action' => 'sale', 'created_at' => now()->subDays(90)]);
        User::factory()->create(['created_at' => now()->subDays(60)]);

        $this->backfill();

        $status = $this->row('activation_status');
        $this->assertSame('activated', $status->value);
        $this->assertSame('activation', $status->group);
        $this->assertSame('string', $status->type);

        // The earlier of the first audit row (90 days) and the first account (60 days).
        $this->assertSame(now()->subDays(90)->toDateString(), Activation::installedAt()->toDateString());
        $this->assertSame(now()->subDays(90)->toDateString(), substr($this->row('installed_at')->value, 0, 10));
        $this->assertSame('activation', $this->row('installed_at')->group);

        // Whatever License::tier() resolved to before: the config default.
        $this->assertSame(config('pos.default_tier'), $this->row('tier')->value);
        $this->assertSame('license', $this->row('tier')->group);
        $this->assertSame(config('pos.default_tier'), License::tier());
    }

    public function test_installed_at_is_the_first_account_when_that_is_older_than_any_audit_row(): void
    {
        AuditLog::create(['action' => 'sale', 'created_at' => now()->subDays(10)]);
        User::factory()->create(['created_at' => now()->subDays(200)]);

        $this->backfill();

        $this->assertSame(now()->subDays(200)->toDateString(), substr($this->row('installed_at')->value, 0, 10));
    }

    public function test_the_backfill_is_audited_with_the_keys_and_values_it_wrote(): void
    {
        User::factory()->create();

        $this->backfill();

        $log = AuditLog::query()->where('action', 'settings_backfill')->sole();
        $this->assertNull($log->user_id);
        $this->assertSame(self::KEYS, array_keys($log->new_values));
        $this->assertSame('activated', $log->new_values['activation_status']);
        $this->assertSame(config('pos.default_tier'), $log->new_values['tier']);
    }

    public function test_it_leaves_no_stale_cache_entries_behind(): void
    {
        config(['cache.default' => 'database']);
        User::factory()->create();

        // Reading an absent key caches the "absent" marker (`N;`), which is the leftover.
        foreach (self::KEYS as $key) {
            Setting::get($key);
        }
        $stale = fn () => DB::table('cache')->where('key', 'like', '%setting:%')->count();
        $this->assertSame(3, $stale());

        $this->backfill();

        $this->assertSame(0, $stale());
        $this->assertSame('activated', Setting::get('activation_status'));
    }

    public function test_an_existing_value_is_never_overwritten(): void
    {
        User::factory()->create();

        foreach ([Activation::TRIAL, Activation::LOCKED, Activation::ACTIVATED] as $status) {
            Setting::set('activation_status', $status, 'activation');
            Setting::set('installed_at', '2025-03-01T09:00:00+00:00', 'activation');
            Setting::set('tier', 'starter', 'license');

            $this->backfill();

            $this->assertSame($status, Setting::get('activation_status'));
            $this->assertSame('2025-03-01T09:00:00+00:00', Setting::get('installed_at'));
            $this->assertSame('starter', Setting::get('tier'));
        }

        $this->assertDatabaseMissing((new AuditLog)->getTable(), ['action' => 'settings_backfill']);
    }

    public function test_only_the_missing_keys_are_filled_in(): void
    {
        User::factory()->create();
        Setting::set('activation_status', Activation::TRIAL, 'activation');

        $this->backfill();

        $this->assertSame(Activation::TRIAL, Setting::get('activation_status'));
        $this->assertNotNull($this->row('installed_at'));
        $this->assertNotNull($this->row('tier'));
        $this->assertSame(['installed_at', 'tier'], array_keys(
            AuditLog::query()->where('action', 'settings_backfill')->sole()->new_values
        ));
    }

    public function test_a_fresh_database_gets_nothing_and_setup_still_starts_the_trial(): void
    {
        $this->backfill();

        foreach (self::KEYS as $key) {
            $this->assertNull($this->row($key), "{$key} must stay unset on a fresh install");
        }
        $this->assertDatabaseMissing((new AuditLog)->getTable(), ['action' => 'settings_backfill']);

        $this->post('/setup', [
            'name' => 'Vendor', 'username' => 'vendor', 'password' => 'a-real-password',
            'password_confirmation' => 'a-real-password', 'language' => 'en', 'tier' => 'standard',
        ])->assertRedirect(route('dashboard'));

        $this->assertSame(Activation::TRIAL, Activation::status());
        $this->assertSame(14, Activation::daysRemaining());
        $this->assertSame('standard', License::tier());
    }

    public function test_running_it_twice_changes_nothing_the_second_time(): void
    {
        User::factory()->create(['created_at' => now()->subDays(30)]);
        $migration = require database_path('migrations/2026_10_18_000001_backfill_license_settings.php');

        $migration->up();
        $after = Setting::query()->whereIn('key', self::KEYS)->orderBy('key')->get(['key', 'value', 'group'])->toArray();

        $this->travel(5)->days();
        $migration->up();

        $this->assertSame($after, Setting::query()->whereIn('key', self::KEYS)->orderBy('key')->get(['key', 'value', 'group'])->toArray());
        $this->assertSame(1, AuditLog::query()->where('action', 'settings_backfill')->count());
    }

    public function test_rolling_back_leaves_the_licence_state_alone(): void
    {
        User::factory()->create();
        $migration = require database_path('migrations/2026_10_18_000001_backfill_license_settings.php');
        $migration->up();

        $migration->down();

        $this->assertSame('activated', Setting::get('activation_status'));
        $this->assertNotNull($this->row('tier'));
    }

    public function test_a_backfilled_install_stays_activated_and_shows_no_countdown(): void
    {
        $admin = User::factory()->admin()->create(['created_at' => now()->subDays(100)]);
        $vendor = User::factory()->superAdmin()->create();

        $this->backfill();
        $this->travel(400)->days();

        $this->actingAs($admin)->get('/dashboard')->assertOk();
        $this->assertFalse(Activation::isLocked());

        $this->actingAs($vendor)->get('/settings?tab=plan')
            ->assertOk()
            ->assertSee('Activated')
            ->assertSee(now()->subDays(500)->toDateString())
            ->assertDontSee('Days remaining');
    }
}
