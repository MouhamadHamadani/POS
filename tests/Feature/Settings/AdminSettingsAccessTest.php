<?php

namespace Tests\Feature\Settings;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * What the client's admin may do in Settings, and what stays with the vendor's super_admin.
 * The gates are on the server (route middleware and the SettingController guard), so every
 * "forbidden" row below is a crafted request, not a hidden tab.
 */
class AdminSettingsAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::set('tier', 'premium', 'license');        // every feature tab exists
    }

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    private function super(): User
    {
        return User::factory()->superAdmin()->create();
    }

    /** tab => a marker only that tab's form renders */
    private function markers(): array
    {
        return [
            'general' => 'name="group" value="general"',
            'currency' => 'name="group" value="currency"',
            'tax' => route('settings.tax.store', [], false),
            'pos' => 'name="group" value="pos"',
            'receipt' => 'name="group" value="receipt"',
            'numbering' => 'name="group" value="numbering"',
            'loyalty' => 'name="group" value="loyalty"',
            'expenses' => route('settings.expense-categories.store', [], false),
            'receipt_template' => route('settings.receipt-template.update', [], false),
            // super_admin only
            'plan' => route('settings.plan.update', [], false),
            'backup' => route('settings.backup.now', [], false),
            'permissions' => route('settings.permissions.update', [], false),
        ];
    }

    private const ADMIN_TABS = ['general', 'currency', 'tax', 'pos', 'receipt', 'numbering', 'loyalty', 'expenses', 'receipt_template'];
    private const SUPER_ONLY_TABS = ['plan', 'backup', 'permissions'];

    // ---- tabs ----------------------------------------------------------------------------

    public function test_an_admin_sees_the_day_to_day_tabs_and_not_the_vendor_ones(): void
    {
        $markers = $this->markers();
        $admin = $this->admin();

        foreach (self::ADMIN_TABS as $tab) {
            $this->actingAs($admin)->get("/settings?tab={$tab}")->assertOk()
                ->assertSee($markers[$tab], false)
                ->assertSee("?tab={$tab}", false);                       // and the tab is in the strip
        }

        foreach (self::SUPER_ONLY_TABS as $tab) {
            $this->actingAs($admin)->get("/settings?tab={$tab}")->assertOk()
                ->assertDontSee($markers[$tab], false)                    // a hand-typed ?tab= renders General instead
                ->assertDontSee("?tab={$tab}", false)
                ->assertSee($markers['general'], false);
        }
    }

    public function test_a_super_admin_sees_every_tab(): void
    {
        $markers = $this->markers();
        $super = $this->super();

        foreach (array_merge(self::ADMIN_TABS, self::SUPER_ONLY_TABS) as $tab) {
            $this->actingAs($super)->get("/settings?tab={$tab}")->assertOk()->assertSee($markers[$tab], false);
        }
    }

    public function test_the_other_roles_have_no_settings_at_all(): void
    {
        foreach ([User::ROLE_MANAGER, User::ROLE_CASHIER, User::ROLE_STOCK] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->actingAs($user)->get('/settings')->assertForbidden();
            $this->actingAs($user)->post('/settings', ['group' => 'general', 'settings' => ['business_name' => 'X']])->assertForbidden();
        }
    }

    // ---- writes the admin may make ---------------------------------------------------------

    /** @return array<string, array{string, array<string, mixed>}> */
    public static function adminGroups(): array
    {
        return [
            'general' => ['general', ['business_name' => 'Corner Shop']],
            'pos behaviour' => ['pos', ['idle_timeout_min' => 30]],
            'receipt' => ['receipt', ['receipt_width' => 58, 'thermal_printer_name' => 'EPSON']],
            'numbering' => ['numbering', ['receipt_prefix' => 'RC']],
            'loyalty' => ['loyalty', ['redemption_rate' => 100]],
            'appearance' => ['appearance', ['rtl' => '0']],
            'currency' => ['currency', ['exchange_rate' => 89500, 'lbp_rounding_step' => 500]],
        ];
    }

    #[DataProvider('adminGroups')]
    public function test_an_admin_can_save_the_day_to_day_setting_groups(string $group, array $settings): void
    {
        $this->actingAs($this->admin())->post(route('settings.update'), ['group' => $group, 'settings' => $settings])
            ->assertRedirect(route('settings.index', ['tab' => $group]))->assertSessionHasNoErrors();

        foreach ($settings as $key => $value) {
            if ($key !== 'rtl') {
                $this->assertEquals($value, Setting::get($key), $key);
            }
        }
    }

    public function test_an_admin_keeps_the_receipt_template_the_test_print_and_the_tax_and_expense_lists(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('settings.receipt-template.update'), ['template_html' => '<p>{{ receipt_number }}</p>', 'paper_size' => '80mm', 'is_active' => 1])
            ->assertRedirect(route('settings.index', ['tab' => 'receipt_template']));
        $this->actingAs($admin)->post(route('settings.receipt.test-print'), ['group' => 'receipt', 'settings' => ['receipt_width' => 80]])->assertOk();
        $this->actingAs($admin)->post(route('settings.tax.store'), ['name' => 'Special', 'rate' => 0.05])->assertRedirect();
        $this->actingAs($admin)->post(route('settings.expense-categories.store'), ['kind' => 'running', 'name' => 'Cleaning'])->assertRedirect();
    }

    // ---- crafted writes the admin must not make ----------------------------------------------

    public function test_an_admin_posting_the_backup_group_is_refused_with_403_and_nothing_is_written(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('settings.update'), ['group' => 'backup', 'settings' => ['retention_days' => 1, 'backup_frequency' => 'hourly', 'cloud_enabled' => 1]])
            ->assertForbidden();

        $this->assertNull(Setting::get('retention_days'));
        $this->assertNull(Setting::get('backup_frequency'));
    }

    public function test_a_backup_key_smuggled_into_another_group_is_refused_too(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('settings.update'), ['group' => 'pos', 'settings' => ['idle_timeout_min' => 45, 'retention_days' => 1]])
            ->assertForbidden();

        $this->assertNotEquals(45, Setting::get('idle_timeout_min'));      // the whole request fell, not just the key
        $this->assertNull(Setting::get('retention_days'));
    }

    public function test_a_super_admin_still_saves_the_backup_group(): void
    {
        $this->actingAs($this->super())->post(route('settings.update'), ['group' => 'backup', 'settings' => ['retention_days' => 14]])
            ->assertRedirect(route('settings.index', ['tab' => 'backup']));

        $this->assertEquals(14, Setting::get('retention_days'));
    }

    public function test_an_admin_cannot_write_the_plan_or_activation_through_the_shared_settings_route(): void
    {
        $this->actingAs($this->admin())->post(route('settings.update'), ['group' => 'general', 'settings' => ['business_name' => 'X', 'tier' => 'premium', 'activation_status' => 'activated']])
            ->assertRedirect();

        $this->assertNotSame('activated', Setting::get('activation_status'));
        $this->assertSame('premium', Setting::get('tier'));                // untouched (it was already premium; the key is not on the form)
        Setting::set('tier', 'starter', 'license');
        $this->actingAs($this->admin())->post(route('settings.update'), ['group' => 'general', 'settings' => ['business_name' => 'X', 'tier' => 'premium']]);
        $this->assertSame('starter', Setting::get('tier'));
    }

    /** @return array<string, array{string, string, array<string, mixed>}> */
    public static function superAdminOnlyRoutes(): array
    {
        return [
            'plan' => ['post', 'settings.plan.update', ['tier' => 'premium']],
            'features' => ['post', 'settings.features.update', ['features' => []]],
            'features reset' => ['post', 'settings.features.reset', []],
            'permissions' => ['post', 'settings.permissions.update', ['settings' => ['bulk_upload_enabled_admin' => 1]]],
            'activate' => ['post', 'settings.activation.activate', ['password' => 'x']],
            'deactivate' => ['post', 'settings.activation.deactivate', ['password' => 'x']],
            'backup now' => ['post', 'settings.backup.now', []],
            'backup restore' => ['post', 'settings.backup.restore', []],
            'backup delete' => ['delete', 'settings.backup.delete', []],
            'backup download' => ['get', 'settings.backup.download', []],
            'trashed products' => ['get', 'products.trashed', []],
        ];
    }

    #[DataProvider('superAdminOnlyRoutes')]
    public function test_the_vendor_only_routes_answer_an_admin_with_403_and_the_others_too(string $verb, string $route, array $payload): void
    {
        $params = in_array($route, ['settings.backup.restore', 'settings.backup.delete', 'settings.backup.download'], true) ? ['filename' => 'x.sqlite'] : [];
        $url = route($route, $params);

        $admin = $this->admin();
        $this->actingAs($admin)->{$verb}($url, $payload)->assertForbidden();

        foreach ([User::ROLE_MANAGER, User::ROLE_CASHIER, User::ROLE_STOCK] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))->{$verb}($url, $payload)->assertForbidden();
        }
    }

    public function test_an_admin_cannot_flip_a_permission_toggle_even_for_their_own_role(): void
    {
        $this->actingAs($this->admin())->post(route('settings.permissions.update'), ['settings' => ['bulk_upload_enabled_admin' => 1]])->assertForbidden();

        $this->assertFalse((bool) Setting::get('bulk_upload_enabled_admin', false));
    }
}
