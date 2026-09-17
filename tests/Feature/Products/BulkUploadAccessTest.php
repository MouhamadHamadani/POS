<?php

namespace Tests\Feature\Products;

use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The toggle half of bulk upload: who may reach the routes at all, and who may
 * change that. See BulkUploadImportTest for the import itself.
 */
class BulkUploadAccessTest extends TestCase
{
    use RefreshDatabase;

    private function enable(string $key): void
    {
        Setting::set($key, '1', 'permissions', 'bool');
    }

    // === Default: off ===

    public function test_admin_is_denied_by_default(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get('/products/import')->assertForbidden();
    }

    public function test_stock_is_denied_by_default(): void
    {
        $this->actingAs(User::factory()->stock()->create())
            ->get('/products/import')->assertForbidden();
    }

    public function test_every_import_route_is_denied_by_default(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/products/import/template')->assertForbidden();
        $this->actingAs($admin)->post('/products/import/preview')->assertForbidden();
        $this->actingAs($admin)->post('/products/import')->assertForbidden();
    }

    // === Toggled on, per role, independently ===

    public function test_admin_toggle_admits_admin_but_not_stock(): void
    {
        $this->enable('bulk_upload_enabled_admin');

        $this->actingAs(User::factory()->admin()->create())
            ->get('/products/import')->assertOk();

        $this->actingAs(User::factory()->stock()->create())
            ->get('/products/import')->assertForbidden();
    }

    public function test_stock_toggle_admits_stock_but_not_admin(): void
    {
        $this->enable('bulk_upload_enabled_stock');

        $this->actingAs(User::factory()->stock()->create())
            ->get('/products/import')->assertOk();

        $this->actingAs(User::factory()->admin()->create())
            ->get('/products/import')->assertForbidden();
    }

    public function test_ineligible_roles_stay_out_even_with_both_toggles_on(): void
    {
        $this->enable('bulk_upload_enabled_admin');
        $this->enable('bulk_upload_enabled_stock');

        // manager and cashier are never eligible, whatever the toggles say.
        $this->actingAs(User::factory()->manager()->create())
            ->get('/products/import')->assertForbidden();

        $this->actingAs(User::factory()->create(['role' => User::ROLE_CASHIER]))
            ->get('/products/import')->assertForbidden();
    }

    public function test_super_admin_is_admitted_without_any_toggle(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create())
            ->get('/products/import')->assertOk();
    }

    // === Only super_admin flips the toggles ===

    public function test_admin_cannot_change_the_toggles(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->post('/settings/permissions', ['settings' => ['bulk_upload_enabled_admin' => '1']])
            ->assertForbidden();

        $this->assertFalse((bool) Setting::get('bulk_upload_enabled_admin', false));
    }

    public function test_stock_cannot_change_the_toggles(): void
    {
        $this->actingAs(User::factory()->stock()->create())
            ->post('/settings/permissions', ['settings' => ['bulk_upload_enabled_stock' => '1']])
            ->assertForbidden();

        $this->assertFalse((bool) Setting::get('bulk_upload_enabled_stock', false));
    }

    public function test_super_admin_can_change_the_toggles(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create())
            ->post('/settings/permissions', ['settings' => ['bulk_upload_enabled_stock' => '1']])
            ->assertRedirect(route('settings.index', ['tab' => 'permissions']));

        $this->assertTrue((bool) Setting::get('bulk_upload_enabled_stock', false));
        // Unsubmitted checkbox stays off.
        $this->assertFalse((bool) Setting::get('bulk_upload_enabled_admin', false));
    }

    public function test_changing_a_toggle_writes_an_audit_entry_with_old_and_new(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)
            ->post('/settings/permissions', ['settings' => ['bulk_upload_enabled_admin' => '1']]);

        $entry = AuditLog::where('action', 'permission_change')->sole();

        $this->assertSame($superAdmin->id, $entry->user_id);
        $this->assertSame(['bulk_upload_enabled_admin' => false], $entry->old_values);
        $this->assertSame(
            ['bulk_upload_enabled_admin' => true, 'role' => User::ROLE_ADMIN],
            $entry->new_values
        );
    }

    public function test_turning_a_toggle_off_is_audited_too(): void
    {
        $this->enable('bulk_upload_enabled_admin');

        $this->actingAs(User::factory()->superAdmin()->create())
            ->post('/settings/permissions', ['settings' => []]);

        $entry = AuditLog::where('action', 'permission_change')->sole();

        $this->assertSame(['bulk_upload_enabled_admin' => true], $entry->old_values);
        $this->assertFalse($entry->new_values['bulk_upload_enabled_admin']);
        $this->assertFalse((bool) Setting::get('bulk_upload_enabled_admin', false));
    }

    public function test_an_unchanged_toggle_is_not_audited(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create())
            ->post('/settings/permissions', ['settings' => []]);

        $this->assertSame(0, AuditLog::where('action', 'permission_change')->count());
    }

    // === UI follows the gate, and only the gate ===

    public function test_menu_hides_and_shows_the_entry_with_the_toggle(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/products')->assertOk()->assertDontSee('Bulk Upload');

        $this->enable('bulk_upload_enabled_admin');

        $this->actingAs($admin)->get('/products')->assertOk()->assertSee('Bulk Upload');
    }

    public function test_permissions_tab_is_super_admin_only(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get('/settings?tab=permissions')->assertOk()
            ->assertDontSee('Allow Admin to bulk upload products');

        $this->actingAs(User::factory()->superAdmin()->create())
            ->get('/settings?tab=permissions')->assertOk()
            ->assertSee('Allow Admin to bulk upload products')
            ->assertSee('Allow Stock to bulk upload products');
    }
}
