<?php

namespace Tests\Feature\Features;

use App\Facades\Feature;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\HeldSale;
use App\Models\InventoryAdjustment;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What a switched-off feature does to the app: the routes behind it refuse, the
 * links to it disappear, and nothing the core flows write is lost.
 */
class FeatureEnforcementTest extends TestCase
{
    use RefreshDatabase;

    private function plan(string $plan): void
    {
        Setting::set('tier', $plan, 'license');
    }

    private function openShiftFor(User $user): Shift
    {
        return Shift::create([
            'user_id' => $user->id, 'opened_at' => now(),
            'opening_cash_usd' => 100, 'opening_cash_lbp' => 0, 'status' => Shift::STATUS_OPEN,
        ]);
    }

    private function assertRefused($response): void
    {
        $response->assertRedirect()->assertSessionHasErrors(['plan' => __('plan.feature_locked')]);
    }

    // --- Expenses -----------------------------------------------------------------

    public function test_switching_expenses_off_closes_the_module_everywhere(): void
    {
        $this->plan('standard');
        Feature::sync(['reports.profit_loss' => false, 'expenses' => false]);

        $manager = User::factory()->manager()->create();
        $admin = User::factory()->admin()->create();

        foreach (['/expenses', '/expenses/create', '/expenses/duplicate-fixed', '/reports/financial/expenses'] as $url) {
            $this->assertRefused($this->actingAs($manager)->get($url));
            $this->actingAs($manager)->getJson($url)->assertForbidden()->assertJson(['error' => __('plan.feature_locked')]);
        }

        $this->assertRefused($this->actingAs($manager)->post('/expenses', ['amount' => 5]));
        $this->assertSame(0, Expense::count());
        $this->assertRefused($this->actingAs($admin)->post('/settings/expense-categories', ['name' => 'X', 'kind' => 'running']));

        // ...and nothing offers a way in.
        $this->actingAs($manager)->get('/dashboard')->assertOk()->assertDontSee('href="/expenses"', false);
        $this->actingAs($manager)->get('/reports')->assertOk()->assertDontSee(route('reports.expenses'), false);
        $this->actingAs($admin)->get('/settings?tab=expenses')->assertOk()->assertDontSee('settings/expense-categories', false);
    }

    public function test_expenses_stay_available_when_the_feature_is_on_and_the_sidebar_shows_them(): void
    {
        $this->plan('starter');
        $manager = User::factory()->manager()->create();

        $this->actingAs($manager)->get('/expenses')->assertOk();
        $this->actingAs($manager)->get('/dashboard')->assertSee('href="/expenses"', false);
    }

    public function test_the_profit_and_loss_report_goes_with_expenses_even_when_the_plan_has_it(): void
    {
        $this->plan('standard');
        // Past the validation, the way a plan change over existing overrides can leave it.
        Setting::set('feature_overrides', ['expenses' => false], 'license', 'json');
        $manager = User::factory()->manager()->create();

        $this->assertRefused($this->actingAs($manager)->get('/reports/financial/pnl'));
        $this->actingAs($manager)->get('/reports')->assertOk()->assertDontSee(route('reports.pnl'), false);
    }

    public function test_drawer_expenses_already_paid_still_show_on_the_shift_close_screen_with_expenses_off(): void
    {
        $this->plan('standard');
        $manager = User::factory()->manager()->create();
        $this->openShiftFor($manager);
        Setting::set('exchange_rate', 90000, 'currency', 'float');

        $this->actingAs($manager)->post('/expenses', [
            'expense_category_id' => \App\Models\ExpenseCategory::where('name', 'Rent')->value('id'),
            'date' => now()->format('Y-m-d'), 'currency' => 'usd', 'amount' => 12.5,
            'paid_from' => 'drawer', 'notes' => 'March rent',
        ])->assertSessionHasNoErrors();
        $this->assertSame(1, Expense::count());

        Feature::sync(['reports.profit_loss' => false, 'expenses' => false]);

        // The module is gone, the record and the drawer arithmetic are not.
        $this->assertSame(1, Expense::count());
        $this->actingAs($manager)->get('/shifts/close')->assertOk()
            ->assertSee(__('shifts.drawer_expenses'))->assertSee('Rent')->assertSee('$12.50');
    }

    // --- Stock movements ------------------------------------------------------------

    public function test_switching_stock_movements_off_hides_the_history_but_sales_keep_writing_it(): void
    {
        $this->plan('standard');
        Feature::override('stock_movements', false);

        $stock = User::factory()->stock()->create();
        $product = Product::factory()->create(['price_usd' => 10, 'cost_usd' => 1, 'stock_qty' => 50, 'is_taxable' => false]);

        foreach (['/products/movements', '/products/movements/export'] as $url) {
            $this->assertRefused($this->actingAs($stock)->get($url));
        }
        $this->actingAs($stock)->get('/products')->assertOk()->assertDontSee('href="/products/movements"', false);
        $this->actingAs($stock)->get(route('products.edit', $product))->assertOk()
            ->assertDontSee(route('products.movements', ['product_id' => $product->id]), false)
            ->assertDontSee(__('products.tab_movements'));

        // A sale still records its stock movement.
        $cashier = User::factory()->create();
        $this->openShiftFor($cashier);
        $before = InventoryAdjustment::count();

        $this->actingAs($cashier)->postJson('/pos/api/sales', [
            'cart' => [['product_id' => $product->id, 'qty' => 2]],
            'payment' => ['method' => 'cash_usd', 'amount_usd' => 100],
        ])->assertCreated();

        $this->assertGreaterThan($before, InventoryAdjustment::count());
        $this->assertEquals(48, $product->fresh()->stock_qty);

        // Switch it back on and the whole history, including that sale, is there.
        Feature::override('stock_movements', null);
        $this->actingAs($stock)->get('/products/movements?product_id='.$product->id.'&from=2000-01-01&to='.now()->format('Y-m-d'))->assertOk();
    }

    // --- One override on a lower plan -------------------------------------------------

    public function test_one_override_on_starter_opens_only_that_report(): void
    {
        $this->plan('starter');
        $manager = User::factory()->manager()->create();

        $this->assertRefused($this->actingAs($manager)->get('/reports/inventory/stock-levels'));

        Feature::override('reports.stock_levels', true);

        $this->actingAs($manager)->get('/reports/inventory/stock-levels')->assertOk();
        $this->actingAs($manager)->get('/reports/inventory/by-date')->assertOk();
        $this->actingAs($manager)->get('/reports')->assertSee(route('reports.stock-levels'), false)
            ->assertDontSee(route('reports.sales-by-product'), false)
            ->assertDontSee(route('reports.pnl'), false);

        // Everything else Starter lacks is still locked.
        foreach (['/reports/sales/by-product', '/reports/financial/pnl', '/returns', '/suppliers', '/customers'] as $url) {
            $this->assertRefused($this->actingAs($manager)->get($url));
        }
    }

    public function test_a_plan_feature_can_be_taken_away_from_one_install(): void
    {
        $this->plan('standard');
        $manager = User::factory()->manager()->create();
        $this->actingAs($manager)->get('/returns')->assertOk();

        Feature::override('returns', false);

        $this->assertRefused($this->actingAs($manager)->get('/returns'));
        $this->actingAs($manager)->get('/dashboard')->assertDontSee('href="/returns"', false);
        $this->actingAs($manager)->get('/customers')->assertOk(); // the rest of Standard is untouched
    }

    // --- A page that is open when the feature goes ----------------------------------

    public function test_the_next_request_after_a_feature_is_switched_off_is_turned_away_gracefully(): void
    {
        $this->plan('standard');
        $manager = User::factory()->manager()->create();

        $this->actingAs($manager)->get('/returns')->assertOk();
        Feature::override('returns', false);

        $response = $this->actingAs($manager)->get('/returns');
        $this->assertRefused($response);
        $this->assertNotSame(url('/returns'), $response->headers->get('Location'));

        // A page that talks to the server in the background gets a JSON answer it can show.
        $this->actingAs($manager)->postJson('/returns/find-sale', ['receipt' => 'X'])
            ->assertForbidden()->assertJson(['error' => __('plan.feature_locked')]);
    }

    // --- Demo ---------------------------------------------------------------------------

    public function test_a_demo_build_reaches_every_gated_route_whatever_the_plan(): void
    {
        $this->plan('starter');
        Setting::set('feature_overrides', ['expenses' => false], 'license', 'json');
        config(['pos.demo_mode' => true]);
        Feature::flush();

        $manager = User::factory()->manager()->create();
        foreach (['/returns', '/suppliers', '/customers', '/expenses', '/reports/financial/pnl', '/products/movements'] as $url) {
            $this->actingAs($manager)->get($url)->assertOk();
        }
    }

    // --- Customers are the loyalty feature's: the sale endpoint ---------------------------

    private function customerRig(): array
    {
        $cashier = User::factory()->create();
        $this->openShiftFor($cashier);
        $product = Product::factory()->create(['price_usd' => 10, 'cost_usd' => 1, 'stock_qty' => 50, 'is_taxable' => false]);
        $customer = Customer::create(['name' => 'Rami', 'customer_group' => 'retail', 'is_active' => true, 'credit_limit' => 100]);

        return [$cashier, $product, $customer];
    }

    public function test_with_loyalty_off_a_walk_in_sale_goes_through_but_the_customer_parts_are_refused(): void
    {
        $this->plan('starter');
        [$cashier, $product, $customer] = $this->customerRig();
        $cart = [['product_id' => $product->id, 'qty' => 1]];

        $this->actingAs($cashier)->postJson('/pos/api/sales', [
            'cart' => $cart, 'payment' => ['method' => 'cash_usd', 'amount_usd' => 10],
        ])->assertCreated();

        $this->actingAs($cashier)->postJson('/pos/api/sales', [
            'cart' => $cart, 'customer_id' => $customer->id,
            'payment' => ['method' => 'cash_usd', 'amount_usd' => 10],
        ])->assertForbidden()->assertJson(['error' => __('plan.feature_locked')]);

        $this->actingAs($cashier)->postJson('/pos/api/sales', [
            'cart' => $cart, 'payment' => ['method' => 'credit', 'amount_credit' => 10],
        ])->assertForbidden();

        $this->actingAs($cashier)->postJson('/pos/api/sales', [
            'cart' => $cart, 'payment' => ['method' => 'cash_usd', 'amount_usd' => 10, 'loyalty_points_redeemed' => 50],
        ])->assertForbidden();

        $this->assertSame(1, Sale::count(), 'only the walk-in sale was recorded');
    }

    public function test_with_loyalty_on_the_same_customer_sale_goes_through(): void
    {
        $this->plan('standard');
        [$cashier, $product, $customer] = $this->customerRig();

        $this->actingAs($cashier)->postJson('/pos/api/sales', [
            'cart' => [['product_id' => $product->id, 'qty' => 1]], 'customer_id' => $customer->id,
            'payment' => ['method' => 'cash_usd', 'amount_usd' => 10],
        ])->assertCreated();

        $this->assertSame($customer->id, Sale::sole()->customer_id);
    }

    public function test_a_hold_with_a_customer_comes_back_as_a_walk_in_cart_once_loyalty_is_off(): void
    {
        $this->plan('standard');
        [$cashier, $product, $customer] = $this->customerRig();

        $this->actingAs($cashier)->postJson('/pos/api/holds', [
            'cart' => [['product_id' => $product->id, 'qty' => 1]], 'customer_id' => $customer->id,
        ])->assertCreated();
        $this->assertSame($customer->id, HeldSale::sole()->customer_id);

        Feature::override('loyalty', false);

        $hold = HeldSale::sole();
        $this->actingAs($cashier)->getJson('/pos/api/holds')->assertOk()->assertJsonPath('0.customer', null);
        $this->actingAs($cashier)->postJson("/pos/api/holds/{$hold->id}/recall")
            ->assertOk()->assertJsonPath('customer_id', null)->assertJsonPath('customer', null);

        // ...and a hold made while it is off carries no customer at all.
        $this->actingAs($cashier)->postJson('/pos/api/holds', [
            'cart' => [['product_id' => $product->id, 'qty' => 1]], 'customer_id' => $customer->id,
        ])->assertCreated();
        $this->assertNull(HeldSale::sole()->customer_id);
    }

    public function test_the_till_offers_the_customer_button_only_with_loyalty_on(): void
    {
        $cashier = User::factory()->create();
        $this->openShiftFor($cashier);

        $this->plan('standard');
        $this->actingAs($cashier)->get('/pos')->assertOk()->assertSee('Add customer (F3)');

        Feature::override('loyalty', false);
        $this->actingAs($cashier)->get('/pos')->assertOk()->assertDontSee('Add customer (F3)');
    }

    // --- Template tab --------------------------------------------------------------------

    public function test_the_receipt_template_tab_follows_its_feature(): void
    {
        $this->plan('standard');
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get('/settings')->assertDontSee('?tab=receipt_template', false);

        Feature::override('receipts.custom_templates', true);
        $this->actingAs($admin)->get('/settings')->assertSee('?tab=receipt_template', false);
        $this->actingAs($admin)->post('/settings/receipt-template', ['paper_size' => '80mm', 'template_html' => '<b>x</b>'])
            ->assertRedirect(route('settings.index', ['tab' => 'receipt_template']));
    }
}
