<?php

namespace Tests\Feature\Features;

use App\Exceptions\FeatureDependencyException;
use App\Facades\Feature;
use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use App\Services\FeatureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * The resolver: who decides whether a feature is on (core, override, plan,
 * dependency), the two-way dependency rules, and the shape of what gets stored.
 */
class FeatureServiceTest extends TestCase
{
    use RefreshDatabase;

    private function plan(string $plan): void
    {
        Setting::set('tier', $plan, 'license');
    }

    private function overrides(array $map): void
    {
        Setting::set('feature_overrides', $map, 'license', 'json');
    }

    private function storedOverrides(): array
    {
        return json_decode((string) Setting::query()->where('key', 'feature_overrides')->value('value'), true) ?? [];
    }

    /** A small registry that does not depend on the real catalogue: a -> b -> c, plus a free-standing d. */
    private function syntheticCatalogue(array $planFeatures = ['a', 'b', 'c', 'd']): void
    {
        config([
            'features.features' => [
                'core_x' => ['group' => 'core', 'core' => true],
                'a' => ['group' => 'g', 'requires' => ['b']],
                'b' => ['group' => 'g', 'requires' => ['c', 'core_x']],
                'c' => ['group' => 'g', 'requires' => []],
                'd' => ['group' => 'g', 'requires' => []],
            ],
            'features.groups' => ['g'],
            'features.plans' => ['full' => ['limits' => ['max_users' => null, 'max_devices' => null, 'roles' => []], 'features' => $planFeatures]],
            'pos.default_tier' => 'full',
        ]);
        Feature::flush();
    }

    // --- Default and grace ---------------------------------------------------

    public function test_an_install_that_never_had_a_plan_has_every_feature(): void
    {
        $this->assertNull(Setting::get('tier'));
        $this->assertSame('premium', Feature::plan());

        foreach (Feature::all() as $key => $state) {
            $this->assertTrue($state['enabled'], "{$key} should be on for an install that predates plans");
        }
    }

    public function test_an_unknown_plan_value_falls_back_to_the_default(): void
    {
        $this->plan('enterprise-deluxe');

        $this->assertSame('premium', Feature::plan());
        $this->assertTrue(Feature::enabled('returns'));
    }

    public function test_an_unknown_key_is_never_enabled(): void
    {
        $this->assertFalse(Feature::enabled('no.such.feature'));
    }

    // --- Resolution order: core, override, plan, dependency -------------------

    public function test_core_keys_are_always_on_and_cannot_be_switched(): void
    {
        $this->plan('starter');
        $this->overrides(['pos' => false, 'sales_history' => false]);

        $this->assertTrue(Feature::enabled('pos'));
        $this->assertTrue(Feature::enabled('sales_history'));
        $this->assertSame('core', Feature::all()['pos']['source']);

        $this->expectException(InvalidArgumentException::class);
        Feature::override('pos', false);
    }

    public function test_the_plan_preset_decides_when_there_is_no_override(): void
    {
        $this->plan('starter');
        $this->assertFalse(Feature::enabled('returns'));
        $this->assertSame('plan', Feature::all()['returns']['source']);

        $this->plan('standard');
        $this->assertTrue(Feature::enabled('returns'));
        $this->assertFalse(Feature::enabled('receipts.custom_templates'));

        $this->plan('premium');
        $this->assertTrue(Feature::enabled('receipts.custom_templates'));
    }

    public function test_an_override_beats_the_plan_in_both_directions(): void
    {
        $this->plan('starter');
        Feature::override('reports.stock_levels', true);

        $this->assertTrue(Feature::enabled('reports.stock_levels'));
        $this->assertSame('override', Feature::all()['reports.stock_levels']['source']);
        $this->assertFalse(Feature::enabled('reports.sales_by_product'), 'only the overridden key changes');

        $this->plan('standard');
        Feature::override('returns', false);

        $this->assertFalse(Feature::enabled('returns'));
        $this->assertSame('override', Feature::all()['returns']['source']);
        $this->assertTrue(Feature::enabled('loyalty'));
    }

    public function test_a_dependency_that_is_off_blocks_a_feature_that_is_wanted(): void
    {
        $this->plan('standard');
        // Written straight to the setting, past the validation, the way a hand-edited
        // database or a plan change could leave it.
        $this->overrides(['expenses' => false]);

        $state = Feature::all()['reports.profit_loss'];
        $this->assertFalse($state['enabled']);
        $this->assertTrue($state['wanted']);
        $this->assertSame('dependency', $state['source']);
        $this->assertSame(['expenses'], $state['missing']);
        $this->assertFalse(Feature::enabled('reports.profit_loss'));
    }

    public function test_dependencies_resolve_through_a_chain_and_a_cycle_does_not_hang(): void
    {
        $this->syntheticCatalogue(['a', 'b', 'd']); // c is not in the plan
        $this->assertFalse(Feature::enabled('b'));
        $this->assertFalse(Feature::enabled('a'));
        $this->assertTrue(Feature::enabled('d'));

        $this->syntheticCatalogue();
        $this->assertTrue(Feature::enabled('a'));

        config(['features.features.c.requires' => ['a']]); // a -> b -> c -> a
        Feature::flush();
        $this->assertFalse(Feature::enabled('a'));
    }

    // --- Dependencies are enforced both ways when changing ----------------------

    public function test_switching_off_something_that_other_features_need_is_refused(): void
    {
        $this->plan('standard');

        try {
            Feature::override('expenses', false);
            $this->fail('expected a dependency refusal');
        } catch (FeatureDependencyException $e) {
            $this->assertSame(['reports.profit_loss'], $e->broken);
            $this->assertStringContainsString(Feature::label('expenses'), $e->getMessage());
            $this->assertStringContainsString(Feature::label('reports.profit_loss'), $e->getMessage());
        }

        $this->assertTrue(Feature::enabled('expenses'));
        $this->assertSame([], $this->storedOverrides());
    }

    public function test_switching_on_something_whose_requirements_are_off_is_refused(): void
    {
        $this->plan('standard');
        Feature::override('reports.profit_loss', false);
        Feature::override('expenses', false);

        try {
            Feature::override('reports.profit_loss', true);
            $this->fail('expected a dependency refusal');
        } catch (FeatureDependencyException $e) {
            $this->assertSame(['reports.profit_loss' => ['expenses']], $e->unmet);
            $this->assertStringContainsString(Feature::label('expenses'), $e->getMessage());
        }

        $this->assertFalse(Feature::enabled('reports.profit_loss'));

        // The order that works: requirement first.
        Feature::override('expenses', true);
        Feature::override('reports.profit_loss', true);
        $this->assertTrue(Feature::enabled('reports.profit_loss'));
    }

    public function test_the_refusal_is_the_same_on_the_synthetic_chain(): void
    {
        $this->syntheticCatalogue();
        Feature::override('a', false);
        Feature::override('b', false);
        Feature::override('c', false);
        Feature::override('d', false);

        try {
            Feature::override('a', true); // needs b, b is off
            $this->fail('a needs b');
        } catch (FeatureDependencyException $e) {
            $this->assertSame(['a' => ['b']], $e->unmet);
        }

        Feature::override('c', true);
        Feature::override('b', true);
        Feature::override('a', true);
        $this->assertTrue(Feature::enabled('a'));

        try {
            Feature::override('c', false); // b and a would stop working
            $this->fail('c is still needed');
        } catch (FeatureDependencyException $e) {
            $this->assertEqualsCanonicalizing(['a', 'b'], $e->broken);
        }
    }

    public function test_the_refusal_names_only_what_is_in_the_way_not_everything_else_in_the_same_save(): void
    {
        $this->plan('standard');

        try {
            Feature::sync(['returns' => false, 'expenses' => false]);
            $this->fail('expected a dependency refusal');
        } catch (FeatureDependencyException $e) {
            $this->assertStringContainsString(Feature::label('expenses'), $e->getMessage());
            $this->assertStringContainsString(Feature::label('reports.profit_loss'), $e->getMessage());
            $this->assertStringNotContainsString(Feature::label('returns'), $e->getMessage());
        }
    }

    public function test_switching_a_feature_and_what_it_needs_together_in_one_save_is_fine(): void
    {
        $this->plan('standard');
        Feature::sync(['reports.profit_loss' => false, 'expenses' => false]);

        $this->assertFalse(Feature::enabled('expenses'));
        $this->assertFalse(Feature::enabled('reports.profit_loss'));
    }

    public function test_a_save_that_fails_part_way_writes_nothing(): void
    {
        $this->plan('standard');

        try {
            Feature::sync(['returns' => false, 'expenses' => false]); // the second one is refused
            $this->fail('expected a refusal');
        } catch (FeatureDependencyException) {
        }

        $this->assertTrue(Feature::enabled('returns'));
        $this->assertSame([], $this->storedOverrides());
        $this->assertSame(0, AuditLog::where('action', 'feature_override_change')->count());
    }

    public function test_an_unrelated_edit_is_not_blocked_by_a_problem_that_was_already_there(): void
    {
        $this->plan('standard');
        $this->overrides(['expenses' => false]); // leaves profit_loss blocked

        Feature::override('returns', false);

        $this->assertFalse(Feature::enabled('returns'));
    }

    // --- What gets stored -------------------------------------------------------

    public function test_a_state_equal_to_the_plans_is_stored_as_no_override(): void
    {
        $this->plan('standard');

        Feature::override('returns', false);
        $this->assertSame(['returns' => false], $this->storedOverrides());

        Feature::override('returns', true); // same as the plan again
        $this->assertSame([], $this->storedOverrides());

        Feature::override('loyalty', false);
        Feature::override('loyalty', null); // follow the plan
        $this->assertSame([], $this->storedOverrides());
    }

    public function test_stale_overrides_are_ignored_and_cleaned_up_on_the_next_write(): void
    {
        $this->plan('standard');
        $this->overrides(['ghost.feature' => true, 'pos' => false, 'returns' => 'yes', 'loyalty' => false]);

        $this->assertSame(['loyalty' => false], Feature::overrides());
        $this->assertTrue(Feature::enabled('returns'), 'a non-boolean value is not an override');
        $this->assertTrue(Feature::enabled('pos'));

        Feature::override('suppliers', false);

        $this->assertSame(['loyalty' => false, 'suppliers' => false], $this->storedOverrides());
    }

    public function test_a_preset_key_the_config_no_longer_has_is_ignored(): void
    {
        $this->syntheticCatalogue(['a', 'b', 'c', 'gone']);

        $this->assertTrue(Feature::enabled('a'));
        $this->assertFalse(Feature::enabled('gone'));
        $this->assertNotContains('gone', Feature::preset());
    }

    // --- Cache -------------------------------------------------------------------

    public function test_the_resolved_map_follows_every_kind_of_change_immediately(): void
    {
        $this->plan('premium');
        $this->assertTrue(Feature::enabled('returns')); // resolves and memoises

        Setting::set('tier', 'starter', 'license'); // a plain setting write, not through the service
        $this->assertFalse(Feature::enabled('returns'));

        Feature::override('returns', true);
        $this->assertTrue(Feature::enabled('returns'));

        Setting::set('feature_overrides', [], 'license', 'json');
        $this->assertFalse(Feature::enabled('returns'));

        Feature::setPlan('standard');
        $this->assertTrue(Feature::enabled('returns'));
    }

    // --- Demo --------------------------------------------------------------------

    public function test_a_demo_build_has_every_feature_whatever_the_plan_and_overrides_say(): void
    {
        $this->plan('starter');
        $this->overrides(['expenses' => false]);
        config(['pos.demo_mode' => true]);
        Feature::flush();

        foreach (Feature::all() as $key => $state) {
            $this->assertTrue($state['enabled'], $key);
        }
        $this->assertTrue(Feature::enabled('receipts.custom_templates'));
        $this->assertTrue(Feature::enabled('reports.profit_loss'));
        $this->assertSame('demo', Feature::all()['returns']['source']);
    }

    // --- Audit -------------------------------------------------------------------

    public function test_every_change_is_audited_with_old_and_new_values(): void
    {
        $this->plan('standard');
        $actor = User::factory()->superAdmin()->create()->id;

        Feature::setPlan('starter', $actor);
        $log = AuditLog::where('action', 'tier_change')->latest('id')->first();
        $this->assertSame(['tier' => 'standard'], $log->old_values);
        $this->assertSame(['tier' => 'starter'], $log->new_values);
        $this->assertSame($actor, $log->user_id);

        Feature::override('reports.stock_levels', true, $actor);
        $log = AuditLog::where('action', 'feature_override_change')->latest('id')->first();
        $this->assertSame(['feature_overrides' => []], $log->old_values);
        $this->assertSame(['feature_overrides' => ['reports.stock_levels' => true]], $log->new_values);
        $this->assertSame($actor, $log->user_id);

        Feature::reset($actor);
        $log = AuditLog::where('action', 'feature_overrides_reset')->latest('id')->first();
        $this->assertSame(['feature_overrides' => ['reports.stock_levels' => true]], $log->old_values);
        $this->assertSame(['feature_overrides' => []], $log->new_values);
    }

    public function test_a_change_that_changes_nothing_is_not_audited(): void
    {
        $this->plan('standard');

        Feature::override('returns', true); // already what the plan gives
        Feature::reset();

        $this->assertSame(0, AuditLog::whereIn('action', ['feature_override_change', 'feature_overrides_reset'])->count());
    }

    public function test_an_unknown_plan_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Feature::setPlan('platinum');
    }

    // --- Previewing a plan change --------------------------------------------------

    public function test_the_preview_lists_what_a_plan_change_turns_on_and_off(): void
    {
        $this->plan('premium');

        $down = Feature::preview('starter');
        $this->assertEqualsCanonicalizing(
            ['reports.sales_by_product', 'reports.stock_levels', 'reports.profit_loss', 'returns', 'suppliers', 'loyalty', 'sales_channels', 'receipts.custom_templates'],
            $down['off'],
        );
        $this->assertSame([], $down['on']);

        $this->plan('starter');
        Feature::override('reports.stock_levels', true);
        $up = Feature::preview('standard');
        $this->assertNotContains('reports.stock_levels', $up['on'], 'already on through the override');
        $this->assertContains('returns', $up['on']);
        $this->assertSame([], $up['off']);
    }

    // --- The shipped catalogue is coherent --------------------------------------------

    public function test_every_requirement_exists_and_nothing_requires_itself_in_a_loop(): void
    {
        $defs = Feature::definitions();

        foreach ($defs as $key => $def) {
            foreach ($def['requires'] ?? [] as $required) {
                $this->assertArrayHasKey($required, $defs, "{$key} requires unknown {$required}");
            }
        }

        $walk = function (string $key, array $path) use (&$walk, $defs) {
            $this->assertNotContains($key, $path, 'requires cycle: '.implode(' -> ', [...$path, $key]));
            foreach ($defs[$key]['requires'] ?? [] as $required) {
                $walk($required, [...$path, $key]);
            }
        };
        foreach (array_keys($defs) as $key) {
            $walk($key, []);
        }
    }

    public function test_every_plan_preset_is_self_consistent_and_lists_only_real_toggleable_keys(): void
    {
        foreach (Feature::plans() as $plan) {
            $preset = config("features.plans.{$plan}.features");

            foreach ($preset as $key) {
                $this->assertTrue(Feature::isToggleable($key), "{$plan} lists {$key}, which is unknown or core");
            }

            $this->plan($plan);
            foreach ($preset as $key) {
                $this->assertTrue(Feature::enabled($key), "{$plan} lists {$key} but a requirement of it is not in the plan");
            }
        }
    }

    public function test_plans_only_add_to_each_other(): void
    {
        $starter = config('features.plans.starter.features');
        $standard = config('features.plans.standard.features');
        $premium = config('features.plans.premium.features');

        $this->assertSame([], array_diff($starter, $standard));
        $this->assertSame([], array_diff($standard, $premium));
    }

    public function test_every_feature_key_and_group_has_a_label_in_both_languages(): void
    {
        $translator = app('translator');

        foreach (Feature::definitions() as $key => $def) {
            $slug = str_replace('.', '_', $key);
            foreach (['en', 'ar'] as $locale) {
                $this->assertTrue($translator->hasForLocale("features.labels.{$slug}", $locale), "{$locale} label for {$key}");
                $this->assertTrue($translator->hasForLocale("features.groups.{$def['group']}", $locale), "{$locale} group {$def['group']}");
            }
        }
        foreach (config('features.groups') as $group) {
            $this->assertContains($group, array_unique(array_column(Feature::definitions(), 'group')), "group {$group} has no feature");
        }
    }

    public function test_every_feature_key_a_route_asks_for_is_in_the_catalogue(): void
    {
        $used = [];
        foreach (Route::getRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $middleware) {
                if (is_string($middleware) && str_starts_with($middleware, 'feature:')) {
                    $used[substr($middleware, 8)] = $route->uri();
                }
            }
        }

        $this->assertNotEmpty($used);
        foreach ($used as $key => $uri) {
            $this->assertTrue(Feature::isToggleable($key), "{$uri} is gated by feature:{$key}, which is not a toggleable key");
        }
    }

    public function test_the_facade_and_the_helper_share_one_resolver(): void
    {
        $this->plan('starter');

        $this->assertSame(app(FeatureService::class), app(FeatureService::class));
        $this->assertFalse(feature('returns'));

        Feature::override('returns', true);
        $this->assertTrue(feature('returns'));
    }
}
