<?php

namespace Tests\Feature\Activation;

use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use App\Support\Activation;
use App\Support\License;
use App\Support\SuperAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Tier (which features and seats this install bought) and activation (whether
 * it may run at all) are two independent settings on one Plan tab. These pin
 * the combinations that matter and the rule that joins them: a lock wins over
 * every tier, and neither setting ever writes the other.
 */
class PlanTierActivationTest extends TestCase
{
    use RefreshDatabase;

    /** An install on $tier whose activation is $status, installed $daysAgo days ago. */
    private function install(string $tier, string $status, int $daysAgo = 0): void
    {
        Setting::set('tier', $tier, 'license');
        Setting::set('installed_at', now()->subDays($daysAgo)->toIso8601String(), 'activation');
        Setting::set('activation_status', $status, 'activation');
        Setting::set('activation_seen_at', now()->toIso8601String(), 'activation');
    }

    private function vendor(): User
    {
        return User::factory()->superAdmin()->create();
    }

    // === Starter on trial ===

    public function test_a_starter_install_on_trial_runs_normally_but_keeps_its_tier_limits(): void
    {
        $this->install('starter', Activation::TRIAL, 3);
        $manager = User::factory()->manager()->create();
        $vendor = $this->vendor();

        $this->actingAs($manager)->get('/dashboard')->assertOk();

        // Not locked, but still a Starter: a standard-only route is refused by the tier, not the lock.
        $this->actingAs($vendor)->get('/suppliers')->assertSessionHasErrors('plan');
        $this->assertSame('starter', License::tier());
        $this->assertSame(Activation::TRIAL, Activation::status());
    }

    public function test_a_starter_install_has_two_seats_and_the_vendor_does_not_use_them(): void
    {
        $this->install('starter', Activation::TRIAL, 3);
        $vendor = $this->vendor();

        $this->assertSame(0, License::seatsUsed());

        $this->actingAs($vendor)->post('/users', [
            'name' => 'Store Manager', 'username' => 'mgr', 'password' => 'secret123',
            'password_confirmation' => 'secret123', 'role' => User::ROLE_MANAGER, 'language' => 'en', 'is_active' => 1,
        ])->assertRedirect(route('users.index'));
        $this->assertSame(1, License::seatsUsed());

        $this->actingAs($vendor)->post('/users', [
            'name' => 'Second', 'username' => 'second', 'password' => 'secret123',
            'password_confirmation' => 'secret123', 'role' => User::ROLE_CASHIER, 'language' => 'en', 'is_active' => 1,
        ])->assertRedirect(route('users.index'));
        $this->assertSame(2, License::seatsUsed());

        $this->actingAs($vendor)->post('/users', [
            'name' => 'Third', 'username' => 'third', 'password' => 'secret123',
            'password_confirmation' => 'secret123', 'role' => User::ROLE_CASHIER, 'language' => 'en', 'is_active' => 1,
        ])->assertSessionHasErrors('role');
        $this->assertSame(2, License::seatsUsed());
    }

    public function test_the_trial_banner_reaches_a_starter_manager(): void
    {
        $this->install('starter', Activation::TRIAL, 11);

        $this->actingAs(User::factory()->manager()->create())->get('/dashboard')
            ->assertSee('contact your provider');
    }

    // === Standard, locked ===

    public function test_a_locked_standard_install_is_locked_even_on_routes_its_tier_includes(): void
    {
        $this->install('standard', Activation::LOCKED, 20);
        $admin = User::factory()->admin()->create();

        // All of these are open on Standard; the lock has to win.
        foreach (['/suppliers', '/returns', '/customers', '/purchase-orders', '/reports/financial/pnl', '/dashboard'] as $url) {
            $this->get($url)->assertRedirect(route('locked'));
            $this->actingAs($admin)->get($url)->assertRedirect(route('locked'));
        }

        // And on a route the tier does not include, the lock answers first — not the tier refusal.
        $this->actingAs($admin)->post('/settings/receipt-template', ['paper_size' => '80mm'])
            ->assertRedirect(route('locked'))
            ->assertSessionHasNoErrors();
    }

    public function test_a_locked_install_still_shows_the_vendor_both_sections_and_both_work(): void
    {
        $this->install('standard', Activation::LOCKED, 20);
        $vendor = $this->vendor();

        $this->actingAs($vendor)->get('/settings?tab=plan')
            ->assertOk()
            ->assertSee('Plan / Tier')
            ->assertSee('Activation / Trial')
            ->assertSee('Activate permanently');

        // The tier can be set while locked...
        $this->actingAs($vendor)->post('/settings/plan', ['tier' => 'premium'])
            ->assertRedirect(route('settings.index', ['tab' => 'plan']));
        $this->assertSame('premium', License::tier());
        $this->assertSame(Activation::LOCKED, Activation::status());

        // ...and the unlock leaves it exactly as set.
        $this->actingAs($vendor)->post('/settings/activation', ['password' => 'password']);
        $this->assertSame(Activation::ACTIVATED, Activation::status());
        $this->assertSame('premium', License::tier());
    }

    // === Premium, activated ===

    public function test_an_activated_premium_install_never_locks_and_has_everything(): void
    {
        $this->install('premium', Activation::ACTIVATED, 5);
        $admin = User::factory()->admin()->create();

        $this->travel(400)->days();

        foreach (['/suppliers', '/returns', '/customers', '/reports/financial/pnl', '/settings?tab=receipt_template'] as $url) {
            $this->actingAs($admin)->get($url)->assertOk();
        }
        $this->assertFalse(Activation::isLocked());
        $this->assertSame(Activation::ACTIVATED, Activation::status());
    }

    public function test_an_activated_install_shows_no_activate_button(): void
    {
        $this->install('premium', Activation::ACTIVATED, 5);

        $this->actingAs($this->vendor())->get('/settings?tab=plan')
            ->assertSee('Plan / Tier')
            ->assertSee('Activation / Trial')
            ->assertSee('This installation is activated')
            ->assertDontSee('Activate permanently');
    }

    public function test_both_plan_sections_render_in_arabic(): void
    {
        $this->install('standard', Activation::TRIAL, 5);
        Setting::set('language', 'ar', 'appearance');

        $this->actingAs(User::factory()->superAdmin()->create(['language' => 'ar']))->get('/settings?tab=plan')
            ->assertOk()
            ->assertSee('الخطة / الباقة')
            ->assertSee('حفظ الخطة')
            ->assertSee('التفعيل / الفترة التجريبية')
            ->assertSee('تفعيل دائم');
    }

    // === Independence ===

    public function test_changing_the_tier_never_changes_activation(): void
    {
        $vendor = $this->vendor();

        foreach ([Activation::TRIAL, Activation::LOCKED, Activation::ACTIVATED] as $status) {
            $this->install('starter', $status, 5);
            $installedAt = Setting::get('installed_at');

            foreach (['standard', 'premium', 'starter'] as $tier) {
                $this->actingAs($vendor)->post('/settings/plan', ['tier' => $tier]);

                $this->assertSame($tier, License::tier());
                $this->assertSame($status, Activation::status(), "tier -> {$tier} moved activation off {$status}");
                $this->assertSame($installedAt, Setting::get('installed_at'));
            }
        }
    }

    public function test_activating_never_changes_the_tier(): void
    {
        $vendor = $this->vendor();

        foreach (['starter', 'standard', 'premium'] as $tier) {
            $this->install($tier, Activation::LOCKED, 20);

            $this->actingAs($vendor)->post('/settings/activation', ['password' => 'password']);

            $this->assertSame(Activation::ACTIVATED, Activation::status());
            $this->assertSame($tier, License::tier());
        }
    }

    public function test_the_trial_lapsing_never_changes_the_tier(): void
    {
        $this->install('premium', Activation::TRIAL, 20);

        $this->actingAs(User::factory()->admin()->create())->get('/dashboard')->assertRedirect(route('locked'));

        $this->assertSame(Activation::LOCKED, Activation::status());
        $this->assertSame('premium', License::tier());
    }

    // === One tab, vendor only ===

    public function test_the_whole_plan_tab_is_super_admin_only(): void
    {
        $this->install('standard', Activation::TRIAL, 5);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/settings?tab=plan')
            ->assertOk()
            ->assertDontSee('Plan / Tier')
            ->assertDontSee('Activation / Trial')
            ->assertDontSee('Days remaining');

        $this->actingAs($admin)->post('/settings/plan', ['tier' => 'premium'])->assertForbidden();
        $this->actingAs($admin)->post('/settings/activation', ['password' => 'password'])->assertForbidden();

        $this->assertSame('standard', License::tier());
        $this->assertSame(Activation::TRIAL, Activation::status());
    }

    public function test_the_generic_settings_form_cannot_write_the_licence_keys(): void
    {
        $this->install('starter', Activation::TRIAL, 3);
        $installedAt = Setting::get('installed_at');

        foreach ([User::factory()->admin()->create(), $this->vendor()] as $user) {
            $this->actingAs($user)->post('/settings', [
                'group' => 'general',
                'settings' => [
                    'business_name' => 'Corner Shop',
                    'activation_status' => 'activated',
                    'tier' => 'premium',
                    'installed_at' => now()->toIso8601String(),
                    'activation_seen_at' => now()->addYear()->toIso8601String(),
                ],
            ])->assertRedirect();
        }

        $this->assertSame('Corner Shop', Setting::get('business_name'), 'known keys still save');
        $this->assertSame(Activation::TRIAL, Activation::status());
        $this->assertSame('starter', License::tier());
        $this->assertSame($installedAt, Setting::get('installed_at'));
        $this->assertFalse(Activation::clockRolledBack());
    }

    // === A fresh install: tier and trial are set independently ===

    public function test_a_fresh_install_is_starter_and_its_trial_starts_at_the_vendors_first_sign_in(): void
    {
        config([
            'pos.demo_mode' => false,
            'pos.super_admin.username' => 'vendor',
            'pos.super_admin.password_hash' => Hash::make('a-real-password'),
        ]);

        SuperAdmin::ensure();

        // Provisioned: the tier is written, the trial clock is not running yet.
        $this->assertSame('starter', License::tier());
        $this->assertNull(Setting::get('activation_status'));

        $this->post('/login', ['login' => 'vendor', 'password' => 'a-real-password'])->assertRedirect('/pos');

        $this->assertSame('starter', License::tier());
        $this->assertSame(Activation::TRIAL, Activation::status());
        $this->assertSame(14, Activation::daysRemaining());
        $this->assertSame(['super_admin'], User::pluck('role')->all());
        $this->assertSame(0, License::seatsUsed());

        $this->assertDatabaseHas((new AuditLog)->getTable(), ['action' => 'tier_change']);
        $this->assertDatabaseHas((new AuditLog)->getTable(), ['action' => 'super_admin_provisioned']);
    }
}
