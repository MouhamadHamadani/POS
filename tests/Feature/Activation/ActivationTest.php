<?php

namespace Tests\Feature\Activation;

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\Activation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The 14-day trial lock. Pins the three promises that matter: a fresh install
 * locks at day 14 and nothing else, an existing install never locks, and a
 * lock blocks the door without touching a single row of business data.
 */
class ActivationTest extends TestCase
{
    use RefreshDatabase;

    /** A trial install that started $daysAgo days ago. */
    private function trial(int $daysAgo = 0): void
    {
        Setting::set('installed_at', now()->subDays($daysAgo)->toIso8601String(), 'activation');
        Setting::set('activation_status', Activation::TRIAL, 'activation');
        Setting::set('activation_seen_at', now()->toIso8601String(), 'activation');
    }

    private function lockedInstall(): void
    {
        $this->trial(15);
        Setting::set('activation_status', Activation::LOCKED, 'activation');
    }

    // === Rollout ===

    public function test_an_install_with_no_activation_row_is_activated_and_never_locks(): void
    {
        $admin = User::factory()->admin()->create();

        $this->assertSame(Activation::ACTIVATED, Activation::status());

        $this->travel(400)->days();

        $this->actingAs($admin)->get('/dashboard')->assertOk();
        $this->assertFalse(Activation::isLocked());
    }

    public function test_a_demo_build_never_locks(): void
    {
        config(['pos.demo_mode' => true]);
        $this->trial(30);

        $this->assertFalse(Activation::isLocked());
        $this->actingAs(User::factory()->admin()->create())->get('/dashboard')->assertOk();
    }

    // === Trial window ===

    public function test_days_remaining_counts_down_from_fourteen(): void
    {
        $this->trial(0);
        $this->assertSame(14, Activation::daysRemaining());

        $this->trial(10);
        $this->assertSame(4, Activation::daysRemaining());

        $this->trial(13);
        $this->assertSame(1, Activation::daysRemaining());
    }

    public function test_a_trial_install_works_normally_before_day_fourteen(): void
    {
        $this->trial(9);

        $this->actingAs(User::factory()->admin()->create())->get('/dashboard')->assertOk();
        $this->assertSame(Activation::TRIAL, Activation::status());
    }

    public function test_the_warning_banner_starts_on_day_ten_for_the_client_admin_only(): void
    {
        $admin = User::factory()->admin()->create();
        $cashier = User::factory()->create();
        $vendor = User::factory()->superAdmin()->create();

        $this->trial(9);
        $this->actingAs($admin)->get('/dashboard')->assertDontSee('contact your provider');

        $this->trial(10);
        $this->actingAs($admin)->get('/dashboard')
            ->assertOk()
            ->assertSee('Your trial ends in 4 days')
            ->assertSee('contact your provider');
        $this->actingAs($cashier)->get('/dashboard')->assertDontSee('contact your provider');
        $this->actingAs($vendor)->get('/dashboard')->assertDontSee('contact your provider');
    }

    public function test_the_warning_banner_is_in_arabic_when_the_store_language_is_arabic(): void
    {
        Setting::set('language', 'ar', 'appearance');
        $this->trial(11);

        $this->actingAs(User::factory()->admin()->create(['language' => 'ar']))->get('/dashboard')
            ->assertSee('تنتهي الفترة التجريبية بعد 3 أيام');
    }

    // === The lock ===

    public function test_the_trial_locks_at_day_fourteen_and_the_flip_is_audited(): void
    {
        $this->trial(14);

        $this->actingAs(User::factory()->admin()->create())->get('/dashboard')->assertRedirect(route('locked'));

        $this->assertSame(Activation::LOCKED, Activation::status());
        $this->assertDatabaseHas((new AuditLog)->getTable(), ['action' => 'trial_expired']);
    }

    public function test_a_locked_install_redirects_every_non_vendor_route_to_the_lock_screen(): void
    {
        $this->lockedInstall();
        $admin = User::factory()->admin()->create();
        $cashier = User::factory()->create();
        $paths = ['/', '/pos', '/dashboard', '/products', '/reports', '/customers', '/settings', '/settings?tab=plan', '/users', '/profile'];

        // Signed out first: actingAs() below sticks for the rest of the test.
        foreach ($paths as $path) {
            $this->get($path)->assertRedirect(route('locked'));
        }
        foreach ([$admin, $cashier] as $user) {
            foreach ($paths as $path) {
                $this->actingAs($user)->get($path)->assertRedirect(route('locked'));
            }
        }

        // Writes are blocked at the door too, not just reads.
        $this->actingAs($admin)->post('/settings', ['group' => 'general', 'settings' => ['business_name' => 'X']])
            ->assertRedirect(route('locked'));
        $this->actingAs($admin)->post('/settings/activation', ['password' => 'password'])
            ->assertRedirect(route('locked'));
        $this->assertSame(Activation::LOCKED, Activation::status());
    }

    public function test_a_locked_install_answers_api_calls_with_423_instead_of_a_redirect(): void
    {
        $this->lockedInstall();

        $this->actingAs(User::factory()->create())->getJson('/pos/api/products')->assertStatus(423);
    }

    public function test_the_lock_screen_is_reachable_signed_out_and_is_bilingual(): void
    {
        $this->lockedInstall();
        User::factory()->admin()->create(); // a provisioned machine

        $this->get('/locked')->assertOk()
            ->assertSee('Your 14-day trial has ended')
            ->assertSee('Please contact your provider');

        Setting::set('language', 'ar', 'appearance');
        $this->get('/locked')->assertOk()->assertSee('انتهت الفترة التجريبية');
    }

    public function test_the_lock_screen_sends_an_unlocked_install_home(): void
    {
        $this->trial(2);
        User::factory()->admin()->create();

        $this->get('/locked')->assertRedirect('/');
    }

    public function test_the_login_page_and_logout_still_work_when_locked(): void
    {
        $this->lockedInstall();
        $cashier = User::factory()->create();

        $this->get('/login')->assertOk();
        $this->actingAs($cashier)->post('/logout')->assertRedirect('/');
    }

    public function test_locking_leaves_business_data_untouched(): void
    {
        Product::factory()->count(3)->create();
        $users = User::factory()->count(2)->create()->count();

        $this->trial(20);
        $this->get('/')->assertRedirect(route('locked'));

        $this->assertSame(Activation::LOCKED, Activation::status());
        $this->assertSame(3, Product::count());
        $this->assertSame($users, User::count());
    }

    // === Vendor unlock ===

    public function test_a_super_admin_can_still_reach_only_the_plan_tab_while_locked(): void
    {
        $this->lockedInstall();
        $vendor = User::factory()->superAdmin()->create();

        $this->actingAs($vendor)->get('/dashboard')
            ->assertRedirect(route('settings.index', ['tab' => 'plan']));

        $this->actingAs($vendor)->get('/settings?tab=plan')
            ->assertOk()
            ->assertSee('Activate permanently');

        // Asking for another tab still renders the Plan tab, not backups.
        $this->actingAs($vendor)->get('/settings?tab=backup')
            ->assertOk()
            ->assertSee('Activate permanently')
            ->assertDontSee('Backup Now');

        $this->actingAs($vendor)->post('/settings/backup/now')->assertRedirect();
        $this->actingAs($vendor)->post('/settings', ['group' => 'general', 'settings' => ['business_name' => 'X']])->assertRedirect();
        $this->assertNull(Setting::get('business_name'));
    }

    public function test_activation_needs_the_super_admins_own_password(): void
    {
        $this->lockedInstall();
        $vendor = User::factory()->superAdmin()->create();

        $this->actingAs($vendor)->post('/settings/activation', ['password' => 'wrong'])
            ->assertSessionHasErrors('password');

        $this->assertSame(Activation::LOCKED, Activation::status());
    }

    public function test_activating_unlocks_for_everyone_and_is_audited(): void
    {
        $this->lockedInstall();
        $vendor = User::factory()->superAdmin()->create();
        $admin = User::factory()->admin()->create();

        $this->actingAs($vendor)->post('/settings/activation', ['password' => 'password'])
            ->assertRedirect(route('settings.index', ['tab' => 'plan']));

        $this->assertSame(Activation::ACTIVATED, Activation::status());
        $this->assertDatabaseHas((new AuditLog)->getTable(), [
            'action' => 'activate',
            'user_id' => $vendor->id,
        ]);

        $this->actingAs($admin)->get('/dashboard')->assertOk();

        // And it is permanent: a year on, still unlocked.
        $this->travel(365)->days();
        $this->actingAs($admin)->get('/dashboard')->assertOk();
    }

    public function test_the_client_admin_can_never_activate_or_see_the_plan_tab(): void
    {
        $this->trial(5);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post('/settings/activation', ['password' => 'password'])->assertForbidden();
        $this->assertSame(Activation::TRIAL, Activation::status());

        $this->actingAs($admin)->get('/settings?tab=plan')
            ->assertOk()
            ->assertDontSee('Activate permanently')
            ->assertDontSee('Days remaining');
    }

    public function test_only_a_super_admin_may_call_activate_directly(): void
    {
        $this->trial(5);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        Activation::activate(User::factory()->admin()->create());
    }

    // === Tamper resistance ===

    public function test_rolling_the_clock_back_blocks_a_trial_install_until_the_date_is_fixed(): void
    {
        $admin = User::factory()->admin()->create();
        $this->trial(3);

        $this->actingAs($admin)->get('/dashboard')->assertOk();

        $this->travel(-3)->days();
        $this->actingAs($admin)->get('/dashboard')->assertRedirect(route('locked'));
        $this->get('/locked')->assertSee('Check the date on this computer');
        // Not a persisted lock: it clears the moment the date is right again.
        $this->assertSame(Activation::TRIAL, Activation::status());

        $this->travelBack();
        $this->actingAs($admin)->get('/dashboard')->assertOk();
    }

    public function test_a_small_clock_drift_is_tolerated(): void
    {
        $admin = User::factory()->admin()->create();
        $this->trial(3);

        $this->actingAs($admin)->get('/dashboard')->assertOk();

        $this->travel(-6)->hours();
        $this->actingAs($admin)->get('/dashboard')->assertOk();
    }

    public function test_rolling_the_clock_back_cannot_undo_a_lock(): void
    {
        $admin = User::factory()->admin()->create();
        $this->trial(20);

        $this->actingAs($admin)->get('/dashboard')->assertRedirect(route('locked'));

        $this->travel(-30)->days();
        $this->actingAs($admin)->get('/dashboard')->assertRedirect(route('locked'));
        $this->assertSame(Activation::LOCKED, Activation::status());
    }

    public function test_deleting_the_installed_at_row_does_not_restart_the_trial(): void
    {
        AuditLog::create(['action' => 'setup', 'created_at' => now()->subDays(20)]);
        $this->trial(0);
        Setting::query()->where('key', 'installed_at')->delete();
        Cache::forget('setting:installed_at');

        $this->actingAs(User::factory()->admin()->create())->get('/dashboard')->assertRedirect(route('locked'));
        $this->assertSame(Activation::LOCKED, Activation::status());
    }
}
