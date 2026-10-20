<?php

namespace Tests\Feature\Features;

use App\Facades\Feature;
use App\Models\Setting;
use App\Models\User;
use App\Support\License;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Upgrading must not take anything away from an install that already exists, the
 * `feature:` gate must hold on a route it is attached to, and config/features.php
 * must say what claude/feature-matrix.md says it does.
 */
class FeatureUpgradeAndCatalogueTest extends TestCase
{
    use RefreshDatabase;

    /** The keys the three tiers gated before feature overrides existed, by tier. */
    private const OLD_GATED = [
        'starter' => [],
        'standard' => ['reports.sales_by_product', 'reports.stock_levels', 'reports.profit_loss', 'returns', 'suppliers', 'loyalty'],
        'premium' => ['reports.sales_by_product', 'reports.stock_levels', 'reports.profit_loss', 'returns', 'suppliers', 'loyalty', 'receipts.custom_templates'],
    ];

    // --- Upgrade safety ---------------------------------------------------------------

    public function test_an_install_from_before_plans_keeps_every_feature_through_the_backfill(): void
    {
        User::factory()->admin()->create(); // an install with history: the backfill only pins those
        $this->assertNull(Setting::get('tier'));

        (require database_path('migrations/2026_10_18_000001_backfill_license_settings.php'))->up();

        $this->assertSame('premium', Setting::get('tier'));
        $this->assertNull(Setting::query()->where('key', 'feature_overrides')->first(), 'no override row is needed or written');
        $this->assertSame([], Feature::overrides());
        foreach (Feature::all() as $key => $state) {
            $this->assertTrue($state['enabled'], "{$key} was lost by the upgrade");
        }
    }

    public function test_each_plan_includes_exactly_what_its_tier_shipped_with_and_the_new_keys_everywhere(): void
    {
        foreach (self::OLD_GATED as $plan => $shipped) {
            Setting::set('tier', $plan, 'license');

            foreach (['reports.sales_by_product', 'reports.stock_levels', 'reports.profit_loss', 'returns', 'suppliers', 'loyalty', 'receipts.custom_templates'] as $key) {
                $this->assertSame(in_array($key, $shipped, true), Feature::enabled($key), "{$plan}: {$key}");
            }

            // Ungated before, so on in every plan now: nobody loses them.
            $this->assertTrue(Feature::enabled('expenses'), "{$plan}: expenses");
            $this->assertTrue(Feature::enabled('stock_movements'), "{$plan}: stock_movements");
            $this->assertTrue(Feature::enabled('reports.daily_sales'), "{$plan}: daily sales");
        }
    }

    public function test_the_seat_and_role_limits_did_not_move(): void
    {
        $expected = [
            'starter' => [1, 1, ['manager', 'cashier']],
            'standard' => [3, 3, ['manager', 'cashier', 'stock', 'admin']],
            'premium' => [null, null, ['manager', 'cashier', 'stock', 'admin']],
        ];

        foreach ($expected as $plan => [$users, $devices, $roles]) {
            Setting::set('tier', $plan, 'license');

            $this->assertSame($users, License::maxUsers(), $plan);
            $this->assertSame($devices, License::maxDevices(), $plan);
            $this->assertSame($roles, License::allowedRoles(), $plan);
        }
    }

    // --- The gate -----------------------------------------------------------------------

    public function test_the_feature_gate_lets_a_request_through_when_on_and_refuses_it_when_off(): void
    {
        Route::middleware(['web', 'auth', 'feature:returns'])->get('/_probe/returns', fn () => 'through');
        Route::middleware(['web', 'auth', 'feature:no.such.feature'])->get('/_probe/typo', fn () => 'through');
        $user = User::factory()->manager()->create();

        Setting::set('tier', 'standard', 'license');
        $this->actingAs($user)->get('/_probe/returns')->assertOk()->assertSee('through');

        Setting::set('tier', 'starter', 'license');
        $this->actingAs($user)->get('/_probe/returns')->assertRedirect()->assertSessionHasErrors('plan');
        $this->actingAs($user)->getJson('/_probe/returns')->assertForbidden();

        // A key nobody defined is never allowed: a typo fails closed, not open.
        Setting::set('tier', 'premium', 'license');
        $this->actingAs($user)->getJson('/_probe/typo')->assertForbidden();
    }

    // --- config/features.php matches the approved matrix ----------------------------------

    private function matrix(): string
    {
        return (string) file_get_contents(base_path('claude/feature-matrix.md'));
    }

    /** The text between two headings of the matrix. */
    private function section(string $from, string $to): string
    {
        $md = $this->matrix();
        $start = strpos($md, $from);
        $end = strpos($md, $to, $start === false ? 0 : $start);
        $this->assertNotFalse($start, "matrix heading '{$from}' not found");
        $this->assertNotFalse($end, "matrix heading '{$to}' not found");

        return substr($md, $start, $end - $start);
    }

    private function backticked(string $text): array
    {
        preg_match_all('/`([a-z_.]+)`/', $text, $m);

        return array_values(array_unique($m[1]));
    }

    public function test_the_core_keys_are_the_ones_the_matrix_lists(): void
    {
        $listed = $this->backticked($this->section('### Core (not toggleable)', '### Toggleable'));
        $core = array_keys(array_filter(Feature::definitions(), fn ($def) => $def['core'] ?? false));

        $this->assertEqualsCanonicalizing($listed, $core);
    }

    public function test_the_toggleable_keys_and_their_requirements_are_the_ones_the_matrix_lists(): void
    {
        $table = $this->section('### Toggleable', '### Deliberately NOT keyed');
        $rows = [];
        foreach (preg_split('/\R/', $table) as $line) {
            if (preg_match('/^\| \*{0,2}`([a-z_.]+)`/', $line, $m)) {
                $cells = array_map('trim', explode('|', trim($line, '| ')));
                $rows[$m[1]] = $this->backticked($cells[3] ?? ''); // Key | Group | Label | Requires | Gates
            }
        }

        $this->assertEqualsCanonicalizing(array_keys($rows), Feature::toggleable(), 'toggleable keys differ from the matrix');
        foreach ($rows as $key => $requires) {
            $this->assertEqualsCanonicalizing($requires, Feature::definitions()[$key]['requires'] ?? [], "requires of {$key}");
        }
    }

    public function test_the_plan_presets_are_the_ones_the_matrix_lists(): void
    {
        $table = $this->section('## 3. Presets', '## 4.');
        $expected = ['starter' => [], 'standard' => [], 'premium' => []];

        foreach (preg_split('/\R/', $table) as $line) {
            if (! preg_match('/^\| `([a-z_.]+)`/', $line, $m)) {
                continue; // header, separator, the "core keys" row and prose
            }
            // Key | Starter | Standard | Premium
            [, $starter, $standard, $premium] = array_map('trim', explode('|', trim($line, '| ')) + array_fill(0, 4, ''));
            foreach (['starter' => $starter, 'standard' => $standard, 'premium' => $premium] as $plan => $cell) {
                if (str_contains($cell, '✔')) {
                    $expected[$plan][] = $m[1];
                }
            }
        }

        foreach ($expected as $plan => $keys) {
            $this->assertNotEmpty($keys, "no preset rows parsed for {$plan}");
            $this->assertEqualsCanonicalizing($keys, Feature::preset($plan), "preset of {$plan}");
        }
    }
}
