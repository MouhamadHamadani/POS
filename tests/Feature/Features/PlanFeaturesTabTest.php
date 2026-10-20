<?php

namespace Tests\Feature\Features;

use App\Facades\Feature;
use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use App\Support\Activation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Settings > Plan & Features: vendor-only, always reachable by the vendor, and every
 * change it makes is checked against the dependencies and written to the audit log.
 */
class PlanFeaturesTabTest extends TestCase
{
    use RefreshDatabase;

    private function plan(string $plan): void
    {
        Setting::set('tier', $plan, 'license');
    }

    private function vendor(): User
    {
        return User::factory()->superAdmin()->create();
    }

    /**
     * The form as the browser would submit it: every feature the page currently shows as on
     * is ticked, with $changes (key => bool) applied on top.
     */
    private function form(array $changes = []): array
    {
        $on = [];
        foreach (Feature::toggleable() as $key) {
            $want = $changes[$key] ?? Feature::all()[$key]['wanted'];
            if ($want) {
                $on[$key] = '1';
            }
        }

        return ['features' => $on];
    }

    // --- Who can see and use it -----------------------------------------------------

    public function test_the_vendor_sees_the_tab_with_the_plan_and_every_feature_grouped(): void
    {
        $this->plan('standard');

        $this->actingAs($this->vendor())->get('/settings?tab=plan')
            ->assertOk()
            ->assertSee('Plan &amp; Features', false)
            ->assertSee('name="tier"', false)
            ->assertSee(Feature::groupLabel('finance'))
            ->assertSee(Feature::label('expenses'))
            ->assertSee(Feature::label('reports.profit_loss'))
            ->assertSee('name="features[expenses]"', false)
            ->assertSee(__('features.reset'))
            ->assertSee(Feature::label('auth')); // the always-on list
    }

    public function test_nobody_but_the_vendor_can_see_or_use_it(): void
    {
        $this->plan('standard');
        $before = Feature::overrides();

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get('/settings?tab=plan')->assertOk()
            ->assertDontSee('features-form', false)
            ->assertDontSee(__('features.section'))
            ->assertDontSee('name="tier"', false);

        foreach ([$admin, User::factory()->manager()->create(), User::factory()->create(), User::factory()->stock()->create()] as $user) {
            $this->actingAs($user)->post('/settings/features', $this->form(['returns' => false]))->assertForbidden();
            $this->actingAs($user)->postJson('/settings/features', $this->form(['returns' => false]))->assertForbidden();
            $this->actingAs($user)->post('/settings/features/reset')->assertForbidden();
            $this->actingAs($user)->post('/settings/plan', ['tier' => 'starter'])->assertForbidden();
        }

        $this->assertSame($before, Feature::overrides());
        $this->assertTrue(Feature::enabled('returns'));
        $this->assertSame('standard', Feature::plan());
    }

    public function test_a_guest_is_sent_to_sign_in(): void
    {
        $this->post('/settings/features', [])->assertRedirect('/login');
        $this->post('/settings/features/reset')->assertRedirect('/login');
    }

    public function test_the_generic_settings_form_cannot_write_overrides(): void
    {
        $this->plan('standard');

        foreach ([User::factory()->admin()->create(), $this->vendor()] as $user) {
            $this->actingAs($user)->post('/settings', [
                'group' => 'general',
                'settings' => ['business_name' => 'Shop', 'feature_overrides' => '{"returns":false}', 'tier' => 'starter'],
            ]);
        }

        $this->assertSame([], Feature::overrides());
        $this->assertSame('standard', Feature::plan());
    }

    // --- The vendor is never locked out of it ----------------------------------------

    public function test_the_vendor_keeps_the_tab_and_its_endpoints_whatever_the_plan_and_overrides_switch_off(): void
    {
        $this->plan('starter');
        // Everything that can be switched off, switched off (profit & loss first: it needs expenses).
        Feature::sync(array_fill_keys(Feature::toggleable(), false));
        foreach (Feature::toggleable() as $key) {
            $this->assertFalse(Feature::enabled($key), $key);
        }

        $vendor = $this->vendor();

        $this->actingAs($vendor)->get('/settings?tab=plan')->assertOk()->assertSee('name="features[expenses]"', false);
        $this->actingAs($vendor)->post('/settings/features', $this->form(['expenses' => true]))
            ->assertRedirect(route('settings.index', ['tab' => 'plan']))->assertSessionHasNoErrors();
        $this->assertTrue(Feature::enabled('expenses'));
        $this->actingAs($vendor)->post('/settings/features/reset')->assertSessionHasNoErrors();
        $this->actingAs($vendor)->post('/settings/plan', ['tier' => 'standard'])->assertSessionHasNoErrors();
        $this->assertSame('standard', Feature::plan());
    }

    public function test_the_vendor_keeps_them_on_a_locked_install_too(): void
    {
        $this->plan('standard');
        Setting::set('installed_at', now()->subDays(40)->toIso8601String(), 'activation');
        Setting::set('activation_status', Activation::LOCKED, 'activation');
        Setting::set('activation_lock_reason', Activation::REASON_TRIAL_EXPIRED, 'activation');
        Setting::set('activation_seen_at', now()->toIso8601String(), 'activation');
        $vendor = $this->vendor();

        $this->assertTrue(Activation::isLocked());
        $this->actingAs(User::factory()->manager()->create())->get('/returns')->assertRedirect(route('locked'));
        $this->actingAs($vendor)->get('/settings?tab=plan')->assertOk()->assertSee('name="features[expenses]"', false);
        $this->actingAs($vendor)->post('/settings/features', $this->form(['returns' => false]))
            ->assertRedirect(route('settings.index', ['tab' => 'plan']));
        $this->assertFalse(Feature::enabled('returns'));
    }

    // --- Saving ----------------------------------------------------------------------

    public function test_saving_stores_only_what_differs_from_the_plan_and_audits_it(): void
    {
        $this->plan('starter');
        $vendor = $this->vendor();

        $this->actingAs($vendor)->post('/settings/features', $this->form(['reports.stock_levels' => true]))
            ->assertRedirect(route('settings.index', ['tab' => 'plan']))
            ->assertSessionHas('success', __('messages.features_saved'));

        $this->assertSame(['reports.stock_levels' => true], Feature::overrides());
        $this->assertTrue(Feature::enabled('reports.stock_levels'));

        $log = AuditLog::where('action', 'feature_override_change')->latest('id')->first();
        $this->assertSame($vendor->id, $log->user_id);
        $this->assertSame(['feature_overrides' => []], $log->old_values);
        $this->assertSame(['feature_overrides' => ['reports.stock_levels' => true]], $log->new_values);

        // The same form submitted again changes nothing and writes nothing more.
        $this->actingAs($vendor)->post('/settings/features', $this->form());
        $this->assertSame(1, AuditLog::where('action', 'feature_override_change')->count());
    }

    public function test_a_save_that_breaks_a_dependency_is_refused_with_a_message_naming_both_sides(): void
    {
        $this->plan('standard');
        $vendor = $this->vendor();

        $response = $this->actingAs($vendor)->post('/settings/features', $this->form(['expenses' => false]))
            ->assertRedirect(route('settings.index', ['tab' => 'plan']));

        $message = __('features.errors.required_by', [
            'feature' => Feature::label('expenses'), 'dependents' => Feature::label('reports.profit_loss'),
        ]);
        $response->assertSessionHasErrors(['features' => $message]);
        $this->assertTrue(Feature::enabled('expenses'));
        $this->assertSame([], Feature::overrides());
        $this->assertSame(0, AuditLog::where('action', 'feature_override_change')->count());

        // The message is on the page the vendor lands on.
        $this->actingAs($vendor)->followingRedirects()->post('/settings/features', $this->form(['expenses' => false]))
            ->assertSee(e($message), false);
    }

    public function test_a_blocked_feature_is_shown_as_blocked_with_what_it_needs(): void
    {
        $this->plan('standard');
        Setting::set('feature_overrides', ['expenses' => false], 'license', 'json');

        $this->actingAs($this->vendor())->get('/settings?tab=plan')
            ->assertOk()
            ->assertSee(__('features.source.dependency'))
            ->assertSee(__('features.blocked_needs', ['missing' => Feature::label('expenses')]))
            ->assertSee(__('features.source.override'));
    }

    // --- Reset -------------------------------------------------------------------------

    public function test_reset_returns_the_install_to_its_plan_and_is_audited(): void
    {
        $this->plan('standard');
        Feature::sync(['returns' => false, 'receipts.custom_templates' => true]);
        $vendor = $this->vendor();

        $this->actingAs($vendor)->post('/settings/features/reset')
            ->assertRedirect(route('settings.index', ['tab' => 'plan']))
            ->assertSessionHas('success', __('messages.features_reset', ['plan' => __('plan.tier.standard')]));

        $this->assertSame([], Feature::overrides());
        $this->assertTrue(Feature::enabled('returns'));
        $this->assertFalse(Feature::enabled('receipts.custom_templates'));

        $log = AuditLog::where('action', 'feature_overrides_reset')->latest('id')->first();
        $this->assertSame(['feature_overrides' => ['returns' => false, 'receipts.custom_templates' => true]], $log->old_values);
        $this->assertSame(['feature_overrides' => []], $log->new_values);
        $this->assertSame($vendor->id, $log->user_id);
    }

    // --- Changing the plan --------------------------------------------------------------

    public function test_the_page_carries_the_diff_for_every_plan_so_the_confirm_can_show_it(): void
    {
        $this->plan('premium');
        Feature::override('stock_movements', false);

        $html = $this->actingAs($this->vendor())->get('/settings?tab=plan')->assertOk()->getContent();

        foreach (['starter', 'standard', 'premium'] as $plan) {
            $this->assertStringContainsString(__('features.plan_change_title', ['plan' => __('plan.tier.'.$plan)]), $html);
        }
        $this->assertStringContainsString(Feature::label('receipts.custom_templates'), $html);
        $this->assertStringContainsString(__('features.confirm_plan'), $html);
        $this->assertStringContainsString(__('features.no_changes'), $html);
    }

    public function test_changing_the_plan_is_audited_and_keeps_the_overrides(): void
    {
        $this->plan('starter');
        Feature::override('reports.stock_levels', true);
        $vendor = $this->vendor();

        $this->actingAs($vendor)->post('/settings/plan', ['tier' => 'standard'])
            ->assertRedirect(route('settings.index', ['tab' => 'plan']));

        $this->assertSame('standard', Feature::plan());
        $this->assertSame(['reports.stock_levels' => true], Feature::overrides());
        $log = AuditLog::where('action', 'tier_change')->latest('id')->first();
        $this->assertSame(['tier' => 'starter'], $log->old_values);
        $this->assertSame(['tier' => 'standard'], $log->new_values);
    }

    // --- Arabic / RTL ---------------------------------------------------------------------

    public function test_the_tab_renders_in_arabic_right_to_left(): void
    {
        $this->plan('standard');
        Feature::override('returns', false);
        $vendor = User::factory()->superAdmin()->create(['language' => 'ar']);

        $this->actingAs($vendor)->get('/settings?tab=plan')
            ->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('الخطة والميزات')
            ->assertSee(__('features.section', [], 'ar'))
            ->assertSee(__('features.labels.expenses', [], 'ar'))
            ->assertSee(__('features.groups.finance', [], 'ar'))
            ->assertSee(__('features.source.override', [], 'ar'))
            ->assertSee(__('features.reset', [], 'ar'))
            ->assertSee(__('features.save', [], 'ar'))
            ->assertDontSee('Reset to plan defaults')
            ->assertDontSee('Save features');
    }

    public function test_the_dependency_refusal_is_in_arabic_for_an_arabic_vendor(): void
    {
        $this->plan('standard');
        $vendor = User::factory()->superAdmin()->create(['language' => 'ar']);

        $this->actingAs($vendor)->post('/settings/features', $this->form(['expenses' => false]))
            ->assertSessionHasErrors(['features' => __('features.errors.required_by', [
                'feature' => __('features.labels.expenses', [], 'ar'),
                'dependents' => __('features.labels.reports_profit_loss', [], 'ar'),
            ], 'ar')]);
    }

    // --- Demo ------------------------------------------------------------------------------

    public function test_a_demo_build_refuses_to_change_features(): void
    {
        config(['pos.demo_mode' => true]);
        $vendor = $this->vendor();

        $this->actingAs($vendor)->post('/settings/features', $this->form(['expenses' => false]))
            ->assertSessionHasErrors('demo');
        $this->actingAs($vendor)->post('/settings/features/reset')->assertSessionHasErrors('demo');
        $this->assertSame([], Feature::overrides());
    }
}
