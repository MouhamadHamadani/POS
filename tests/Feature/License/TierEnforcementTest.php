<?php

namespace Tests\Feature\License;

use App\Models\AuditLog;
use App\Models\PrintTemplate;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\User;
use App\Support\License;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tier enforcement: what each of the three published plans actually includes.
 *
 * The gates that matter are the route-level ones — hiding a nav link only
 * tidies the screen — so most of these drive the URL directly, the way a
 * curious client with an address bar would.
 */
class TierEnforcementTest extends TestCase
{
    use RefreshDatabase;

    private function tier(string $tier): void
    {
        Setting::set('tier', $tier, 'license');
    }

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    private function superAdmin(): User
    {
        return User::factory()->superAdmin()->create();
    }

    private function staffPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'New Person',
            'username' => 'newperson',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
            'role' => User::ROLE_CASHIER,
            'language' => 'en',
            'is_active' => 1,
        ], $overrides);
    }

    /** Feature areas Starter does not include, by URL. */
    private const STANDARD_ROUTES = [
        '/returns',
        '/suppliers',
        '/purchase-orders',
        '/customers',
        '/reports/inventory/stock-levels',
        '/reports/inventory/by-date',
        '/reports/financial/pnl',
        '/reports/sales/by-product',
    ];

    // --- The grace default: existing installs are untouched ------------------

    public function test_an_install_with_no_tier_setting_is_fully_unlocked(): void
    {
        $this->assertNull(Setting::get('tier'));
        $this->assertSame('premium', License::tier());

        $admin = $this->admin();
        foreach (self::STANDARD_ROUTES as $url) {
            $this->actingAs($admin)->get($url)->assertOk();
        }
    }

    public function test_an_unknown_tier_value_falls_back_to_the_default_rather_than_locking_up(): void
    {
        $this->tier('enterprise-deluxe');

        $this->assertSame('premium', License::tier());
        $this->assertTrue(License::allows('returns'));
    }

    // --- Seats ---------------------------------------------------------------

    public function test_super_admin_does_not_consume_a_seat(): void
    {
        $this->tier('starter');
        $this->superAdmin();
        User::factory()->manager()->create();

        $this->assertSame(1, License::seatsUsed());
        $this->assertTrue(License::isAtUserLimit());
    }

    public function test_starter_blocks_a_second_staff_account_bilingually(): void
    {
        $this->tier('starter');
        $admin = $this->admin(); // the one seat

        $this->actingAs($admin)
            ->post('/users', $this->staffPayload())
            ->assertSessionHasErrors(['role' => __('plan.user_limit', ['used' => 1, 'max' => 1])]);

        $this->assertDatabaseMissing('users', ['username' => 'newperson']);

        // Same refusal, in Arabic, for an Arabic-language operator.
        $admin->update(['language' => 'ar']);
        $this->actingAs($admin)->post('/users', $this->staffPayload())
            ->assertSessionHasErrors(['role' => __('plan.user_limit', ['used' => 1, 'max' => 1], 'ar')]);
    }

    public function test_a_super_admin_cannot_post_past_the_seat_limit_either(): void
    {
        $this->tier('starter');
        $super = $this->superAdmin();
        User::factory()->manager()->create();

        $this->actingAs($super)->post('/users', $this->staffPayload())
            ->assertSessionHasErrors('role');

        // ...but minting another vendor account is not a seat, so it is allowed.
        $this->actingAs($super)->post('/users', $this->staffPayload([
            'username' => 'vendor2',
            'role' => User::ROLE_SUPER_ADMIN,
        ]))->assertRedirect(route('users.index'));
    }

    public function test_standard_blocks_the_fourth_staff_account(): void
    {
        $this->tier('standard');
        $admin = $this->admin();
        User::factory()->manager()->create();
        User::factory()->stock()->create();

        $this->assertSame(3, License::seatsUsed());

        $this->actingAs($admin)->post('/users', $this->staffPayload())
            ->assertSessionHasErrors('role');
    }

    public function test_premium_has_no_seat_ceiling(): void
    {
        $this->tier('premium');
        $admin = $this->admin();
        User::factory()->count(20)->create();

        $this->assertNull(License::maxUsers());
        $this->assertFalse(License::isAtUserLimit());

        $this->actingAs($admin)->post('/users', $this->staffPayload())
            ->assertRedirect(route('users.index'));
    }

    // --- Roles ---------------------------------------------------------------

    public function test_starter_offers_only_manager_and_cashier_on_the_user_form(): void
    {
        $this->tier('starter');

        $this->actingAs($this->admin())->get('/users/create')
            ->assertOk()
            ->assertSee('value="manager"', false)
            ->assertSee('value="cashier"', false)
            ->assertDontSee('value="stock"', false)
            ->assertDontSee('value="admin"', false);
    }

    public function test_starter_refuses_a_crafted_stock_keeper_post(): void
    {
        $this->tier('starter');

        // A fresh super_admin leaves the single seat free, so the refusal that
        // lands is the role one and not the seat one.
        $this->actingAs($this->superAdmin())
            ->post('/users', $this->staffPayload(['role' => User::ROLE_STOCK]))
            ->assertSessionHasErrors(['role' => __('plan.role_locked')]);

        $this->assertDatabaseMissing('users', ['username' => 'newperson']);
    }

    public function test_standard_can_create_stock_keepers_and_admins(): void
    {
        $this->tier('standard');
        $super = $this->superAdmin();

        $this->actingAs($super)->post('/users', $this->staffPayload([
            'username' => 'keeper', 'role' => User::ROLE_STOCK,
        ]))->assertRedirect(route('users.index'));

        $this->actingAs($super)->post('/users', $this->staffPayload([
            'username' => 'boss', 'role' => User::ROLE_ADMIN,
        ]))->assertRedirect(route('users.index'));
    }

    public function test_an_existing_user_cannot_be_moved_into_a_role_the_tier_lacks(): void
    {
        $this->tier('starter');
        $super = $this->superAdmin();
        $cashier = User::factory()->create();

        $this->actingAs($super)->put("/users/{$cashier->id}", [
            'name' => $cashier->name,
            'username' => $cashier->username,
            'role' => User::ROLE_STOCK,
            'language' => 'en',
            'is_active' => 1,
        ])->assertSessionHasErrors(['role' => __('plan.role_locked')]);

        $this->assertSame(User::ROLE_CASHIER, $cashier->fresh()->role);
    }

    // --- Feature areas -------------------------------------------------------

    public function test_starter_refuses_standard_only_routes_typed_directly(): void
    {
        $this->tier('starter');
        $admin = $this->admin();

        foreach (self::STANDARD_ROUTES as $url) {
            $response = $this->actingAs($admin)->get($url)
                ->assertRedirect()
                ->assertSessionHasErrors(['plan' => __('plan.feature_locked')]);

            // Anywhere but back to the page that was just refused — a gate that
            // bounces you at yourself is a redirect loop, not a refusal.
            $this->assertNotSame(url($url), $response->headers->get('Location'));
        }
    }

    public function test_starter_refuses_the_till_customer_endpoints_as_json(): void
    {
        $this->tier('starter');
        $cashier = User::factory()->create();
        $this->openShiftFor($cashier);

        $this->actingAs($cashier)
            ->getJson('/pos/api/customers/search?q=ab')
            ->assertForbidden()
            ->assertJson(['error' => __('plan.feature_locked')]);

        // ...and the till doesn't offer a button that would call it.
        $this->actingAs($cashier)->get('/pos')->assertOk()->assertDontSee('Add customer (F3)');
    }

    public function test_daily_sales_and_the_reports_index_ship_on_starter(): void
    {
        $this->tier('starter');
        $admin = $this->admin();

        $this->actingAs($admin)->get('/reports')->assertOk();
        $this->actingAs($admin)->get('/reports/sales/daily')->assertOk();
    }

    public function test_standard_unlocks_every_area_except_the_template_editor(): void
    {
        $this->tier('standard');
        $admin = $this->admin();

        foreach (self::STANDARD_ROUTES as $url) {
            $this->actingAs($admin)->get($url)->assertOk();
        }

        $this->actingAs($admin)->get('/settings?tab=receipt_template')
            ->assertOk()
            ->assertDontSee('Template HTML');

        $this->actingAs($admin)->post('/settings/receipt-template', [
            'template_html' => '<p>hi</p>', 'paper_size' => '80mm', 'is_active' => 1,
        ])->assertSessionHasErrors('plan');

        $this->assertDatabaseCount('print_templates', 0);
    }

    // --- Switching tiers -----------------------------------------------------

    public function test_a_downgrade_takes_effect_on_the_next_request_and_is_audited(): void
    {
        $this->tier('premium');
        $super = $this->superAdmin();
        $admin = $this->admin();

        $this->actingAs($admin)->get('/suppliers')->assertOk();

        $this->actingAs($super)->post('/settings/plan', ['tier' => 'starter'])
            ->assertRedirect(route('settings.index', ['tab' => 'plan']));

        $this->actingAs($admin)->get('/suppliers')->assertRedirect(route('dashboard'));

        $this->assertDatabaseHas('audit_logs', ['action' => 'tier_change']);
        $this->assertSame(
            ['tier' => 'starter'],
            AuditLog::where('action', 'tier_change')->latest('id')->first()->new_values,
        );
    }

    public function test_a_downgrade_leaves_over_limit_accounts_alone_and_only_blocks_new_ones(): void
    {
        $this->tier('premium');
        $admin = $this->admin();
        User::factory()->count(4)->create();

        $this->tier('starter');

        $this->assertSame(5, License::seatsUsed());
        $this->assertSame(5, User::where('role', '!=', User::ROLE_SUPER_ADMIN)->where('is_active', true)->count());

        $this->actingAs($admin)->post('/users', $this->staffPayload())
            ->assertSessionHasErrors('role');
    }

    // --- Who may set the tier ------------------------------------------------

    public function test_the_plan_tab_is_vendor_territory(): void
    {
        $this->tier('starter');

        $this->actingAs($this->superAdmin())->get('/settings?tab=plan')
            ->assertOk()->assertSee('Save Plan');

        // A client's own admin sees no tab, and cannot set a tier by posting.
        $this->actingAs($this->admin())->get('/settings')->assertOk()->assertDontSee('>Plan<', false);
        $this->actingAs($this->admin())->post('/settings/plan', ['tier' => 'premium'])->assertForbidden();

        $this->assertSame('starter', License::tier());
    }

    public function test_setup_records_the_tier_picked_on_the_first_run_screen(): void
    {
        $this->post('/setup', [
            'name' => 'Owner', 'username' => 'owner',
            'password' => 'a-real-password', 'password_confirmation' => 'a-real-password',
            'language' => 'en', 'tier' => 'standard',
        ])->assertRedirect(route('dashboard'));

        $this->assertSame('standard', License::tier());
    }

    public function test_a_setup_that_skips_the_picker_lands_on_starter(): void
    {
        $this->post('/setup', [
            'name' => 'Owner', 'username' => 'owner',
            'password' => 'a-real-password', 'password_confirmation' => 'a-real-password',
            'language' => 'en',
        ])->assertRedirect(route('dashboard'));

        $this->assertSame('starter', License::tier());
    }

    // --- Premium's receipt template ------------------------------------------

    public function test_premium_can_save_a_template_and_receipts_render_it_without_executing_php(): void
    {
        $this->tier('premium');
        $admin = $this->admin();

        $this->actingAs($admin)->post('/settings/receipt-template', [
            'paper_size' => '80mm',
            'is_active' => 1,
            'template_html' => '<html><body>{{business_name}} / {{receipt_number}}'
                .'{{#items}}<span>{{name}} x{{qty}}</span>{{/items}}'
                .'<?php echo strtoupper("pwned"); ?>{{ total_usd }}</body></html>',
        ])->assertRedirect(route('settings.index', ['tab' => 'receipt_template']));

        Setting::set('business_name', 'Souk <Test>');
        $sale = $this->saleFor($admin);

        $html = $this->actingAs($admin)->get("/pos/receipts/{$sale->id}/print?auto=0")
            ->assertOk()->getContent();

        $this->assertStringContainsString('Souk &lt;Test&gt;', $html);
        $this->assertStringContainsString($sale->receipt_number, $html);
        $this->assertStringContainsString('Widget x2', $html);
        $this->assertStringContainsString('12.50', $html);
        // The PHP tag survives as inert text: it was never compiled, so the
        // uppercased payload an evaluated template would have produced is absent.
        $this->assertStringNotContainsString('PWNED', $html);
        $this->assertStringContainsString('<?php echo strtoupper("pwned"); ?>', $html);
        $this->assertStringContainsString('window.print()', $html);
    }

    public function test_a_test_print_shows_the_custom_template_stamped_as_a_test(): void
    {
        $this->tier('premium');
        $admin = $this->admin();
        PrintTemplate::create([
            'name' => 'Receipt', 'type' => 'receipt', 'is_default' => true, 'is_active' => true,
            'paper_size' => '80mm',
            'template_html' => '<html><body>CUSTOM LAYOUT {{business_name}}</body></html>',
        ]);

        // Checking the layout is the whole point of the button, so it has to show
        // the template a real sale would print...
        $response = $this->actingAs($admin)->post('/settings/receipt/test-print', [
            'group' => 'receipt',
            'settings' => ['receipt_width' => 80, 'receipt_header' => '', 'receipt_footer' => ''],
        ])->assertOk();

        $response->assertSee('CUSTOM LAYOUT');

        // ...and it must still be impossible to pass off as a real one.
        $response->assertSee('TEST PRINT')->assertSee('NOT A VALID RECEIPT')->assertSee('طباعة تجريبية — ليست فاتورة');
    }

    public function test_a_demo_build_stamps_a_custom_template_too(): void
    {
        config(['pos.demo_mode' => true]);
        $this->tier('premium');
        $admin = $this->admin();
        PrintTemplate::create([
            'name' => 'Receipt', 'type' => 'receipt', 'is_default' => true, 'is_active' => true,
            'paper_size' => '80mm', 'template_html' => '<html><body>CUSTOM</body></html>',
        ]);

        $sale = $this->saleFor($admin);

        // A custom template carries no notice of its own; without this a demo
        // build would print something that reads as a real transaction record.
        $this->actingAs($admin)->get("/pos/receipts/{$sale->id}/print?auto=0")
            ->assertOk()
            ->assertSee('DEMO RECEIPT')
            ->assertSee('NOT A VALID RECEIPT')
            ->assertSee('إيصال تجريبي — غير صالح');
    }

    public function test_an_install_with_no_template_keeps_printing_the_built_in_layout(): void
    {
        $this->tier('premium');
        $admin = $this->admin();
        $sale = $this->saleFor($admin);

        $this->actingAs($admin)->get("/pos/receipts/{$sale->id}/print?auto=0")
            ->assertOk()->assertViewIs('receipts.thermal');
    }

    public function test_an_inactive_template_is_ignored(): void
    {
        $this->tier('premium');
        $admin = $this->admin();
        PrintTemplate::create([
            'name' => 'Receipt', 'type' => 'receipt', 'template_html' => '<p>custom</p>',
            'paper_size' => '80mm', 'is_default' => true, 'is_active' => false,
        ]);

        $sale = $this->saleFor($admin);

        $this->actingAs($admin)->get("/pos/receipts/{$sale->id}/print?auto=0")
            ->assertOk()->assertViewIs('receipts.thermal');
    }

    private function openShiftFor(User $user): Shift
    {
        return Shift::create([
            'user_id' => $user->id,
            'opened_at' => now(),
            'opening_cash_usd' => 0,
            'opening_cash_lbp' => 0,
            'status' => 'open',
        ]);
    }

    private function saleFor(User $user): Sale
    {
        $sale = Sale::create([
            'shift_id' => $this->openShiftFor($user)->id,
            'receipt_number' => 'REC-TEST-1',
            'user_id' => $user->id,
            'subtotal_usd' => 12.50,
            'discount_amount_usd' => 0,
            'tax_amount_usd' => 0,
            'total_usd' => 12.50,
            'total_lbp' => 1125000,
            'exchange_rate' => 90000,
            'payment_method' => Sale::METHOD_CASH_USD,
            'status' => Sale::STATUS_COMPLETED,
        ]);

        SaleItem::create([
            'sale_id' => $sale->id,
            'product_id' => Product::factory()->create(['name' => 'Widget'])->id,
            'product_name' => 'Widget',
            'qty' => 2,
            'unit_price_usd' => 6.25,
            'discount_amount_usd' => 0,
            'line_total_usd' => 12.50,
        ]);

        return $sale;
    }
}
