<?php

namespace Tests\Feature\Pos;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Retail / wholesale / VIP price lists. The server is the only source of price:
 * the browser says which list it wants, never what a line costs. (The one thing it
 * may propose is a cashier price override, which the server checks in full: see
 * PriceOverrideTest.)
 */
class PriceTierTest extends TestCase
{
    use RefreshDatabase;

    private function userWithShift(array $attrs = []): User
    {
        $user = User::factory()->create($attrs + ['role' => User::ROLE_CASHIER]);
        Shift::create([
            'user_id' => $user->id, 'opened_at' => now(),
            'opening_cash_usd' => 0, 'opening_cash_lbp' => 0, 'status' => Shift::STATUS_OPEN,
        ]);

        return $user;
    }

    private function manager(): User
    {
        return $this->userWithShift(['role' => User::ROLE_MANAGER]);
    }

    /** Untaxed, so every expected number is the plain list price. */
    private function product(array $attrs = []): Product
    {
        return Product::factory()->create($attrs + [
            'price_usd' => 10.00, 'wholesale_price_usd' => 8.00, 'vip_price_usd' => 6.00,
            'cost_usd' => 3.00, 'stock_qty' => 50, 'is_taxable' => false,
        ]);
    }

    private function customer(string $group): Customer
    {
        return Customer::create(['name' => ucfirst($group) . ' Co', 'customer_group' => $group, 'is_active' => true]);
    }

    private function sell(User $user, Product $product, array $extra = [], int $qty = 2): TestResponse
    {
        return $this->actingAs($user)->postJson('/pos/api/sales', $extra + [
            'cart' => [['product_id' => $product->id, 'qty' => $qty]],
            'payment' => ['method' => 'cash_usd', 'amount_usd' => 1000],
        ]);
    }

    public function test_a_wholesale_customer_with_no_tier_posted_is_sold_at_wholesale(): void
    {
        $product = $this->product();
        $customer = $this->customer('wholesale');

        $saleId = $this->sell($this->userWithShift(), $product, ['customer_id' => $customer->id])
            ->assertCreated()->json('sale.id');

        $sale = Sale::with('items')->findOrFail($saleId);
        $this->assertSame('wholesale', $sale->price_tier);
        $this->assertSame('wholesale', $sale->items[0]->price_tier);
        $this->assertEqualsWithDelta(8.00, (float) $sale->items[0]->unit_price_usd, 0.0001);
        $this->assertEqualsWithDelta(16.00, (float) $sale->total_usd, 0.0001);
    }

    public function test_a_manager_can_sell_at_wholesale_without_a_customer(): void
    {
        $product = $this->product();

        $saleId = $this->sell($this->manager(), $product, ['price_tier' => 'wholesale'])
            ->assertCreated()->json('sale.id');

        $sale = Sale::with('items')->findOrFail($saleId);
        $this->assertSame('wholesale', $sale->price_tier);
        $this->assertEqualsWithDelta(8.00, (float) $sale->items[0]->unit_price_usd, 0.0001);
    }

    public function test_vip_uses_the_vip_price(): void
    {
        $saleId = $this->sell($this->manager(), $this->product(), ['price_tier' => 'vip'])
            ->assertCreated()->json('sale.id');

        $this->assertEqualsWithDelta(6.00, (float) Sale::with('items')->find($saleId)->items[0]->unit_price_usd, 0.0001);
    }

    public function test_a_product_without_a_tier_price_falls_back_to_retail(): void
    {
        $product = $this->product(['wholesale_price_usd' => null]);

        $saleId = $this->sell($this->manager(), $product, ['price_tier' => 'wholesale'])
            ->assertCreated()->json('sale.id');

        $sale = Sale::with('items')->findOrFail($saleId);
        $this->assertSame('wholesale', $sale->price_tier); // the list was wholesale; this product just has no special price
        $this->assertEqualsWithDelta(10.00, (float) $sale->items[0]->unit_price_usd, 0.0001);
    }

    public function test_a_price_posted_by_the_browser_is_not_taken_on_trust(): void
    {
        $product = $this->product();

        // A hand-made 1-cent price is a 99.9% cut: past a cashier's limit, so no sale without a manager.
        $this->actingAs($this->userWithShift())->postJson('/pos/api/sales', [
            'cart' => [['product_id' => $product->id, 'qty' => 1, 'unit_price' => 0.01]],
            'payment' => ['method' => 'cash_usd', 'amount_usd' => 20],
        ])->assertStatus(422)->assertJsonStructure(['error' => ['cart.0.price']]);

        $this->assertSame(0, Sale::count());
        $this->assertSame('50.0000', (string) $product->fresh()->stock_qty);
    }

    public function test_an_unknown_price_list_is_rejected(): void
    {
        $this->sell($this->manager(), $this->product(), ['price_tier' => 'platinum'])
            ->assertStatus(422)->assertJsonValidationErrors('price_tier');
        $this->assertSame(0, Sale::count());
    }

    public function test_a_cashier_cannot_switch_the_list_when_the_store_forbids_it(): void
    {
        Setting::set('cashier_can_change_price_tier', '0', 'pos', 'bool');
        $product = $this->product();

        $this->sell($this->userWithShift(), $product, [
            'price_tier' => 'wholesale',
            'customer_id' => $this->customer('retail')->id,
        ])->assertStatus(422)->assertJsonStructure(['error' => ['price_tier']]);

        // Same with no customer at all: the only list left to a locked cashier is retail.
        $this->sell($this->userWithShift(), $product, ['price_tier' => 'vip'])
            ->assertStatus(422)->assertJsonStructure(['error' => ['price_tier']]);

        $this->assertSame(0, Sale::count());
        $this->assertSame('50.0000', (string) $product->fresh()->stock_qty);
    }

    public function test_a_locked_cashier_still_gets_the_customers_own_list(): void
    {
        Setting::set('cashier_can_change_price_tier', '0', 'pos', 'bool');
        $product = $this->product();
        $customer = $this->customer('wholesale');
        $cashier = $this->userWithShift();

        // Posting the customer's own group is not a change...
        $this->sell($cashier, $product, ['price_tier' => 'wholesale', 'customer_id' => $customer->id])
            ->assertCreated();
        // ...and neither is leaving it out.
        $this->sell($cashier, $product, ['customer_id' => $customer->id])->assertCreated();
        // Retail with no customer is the default, so it passes too.
        $this->sell($cashier, $product, ['price_tier' => 'retail'])->assertCreated();

        $this->assertSame(3, Sale::count());
    }

    public function test_a_cashier_may_switch_lists_unless_the_store_says_otherwise(): void
    {
        // No setting row at all = the default, which is "allowed".
        $saleId = $this->sell($this->userWithShift(), $this->product(), ['price_tier' => 'wholesale'])
            ->assertCreated()->json('sale.id');

        $this->assertSame('wholesale', Sale::findOrFail($saleId)->price_tier);
    }

    public function test_the_store_setting_never_restricts_a_manager(): void
    {
        Setting::set('cashier_can_change_price_tier', '0', 'pos', 'bool');

        $this->sell($this->manager(), $this->product(), ['price_tier' => 'vip'])->assertCreated();
    }

    public function test_the_sale_audit_entry_records_the_price_list(): void
    {
        $saleId = $this->sell($this->manager(), $this->product(), ['price_tier' => 'wholesale'])
            ->assertCreated()->json('sale.id');

        $audit = AuditLog::where('action', 'sale')->where('model_id', $saleId)->firstOrFail();
        $this->assertSame('wholesale', $audit->new_values['price_tier']);
    }

    public function test_a_held_sale_comes_back_on_the_same_price_list_and_cart_discount(): void
    {
        $user = $this->manager();
        $product = $this->product();

        $holdId = $this->actingAs($user)->postJson('/pos/api/holds', [
            'cart' => [['product_id' => $product->id, 'qty' => 2]],
            'price_tier' => 'wholesale',
            'cart_discount' => ['type' => 'pct', 'value' => 5],
        ])->assertCreated()->json('id');

        $this->actingAs($user)->postJson("/pos/api/holds/{$holdId}/recall")
            ->assertOk()
            ->assertJsonPath('price_tier', 'wholesale')
            ->assertJsonPath('cart_discount.type', 'pct')
            ->assertJsonPath('cart_discount.value', 5)
            ->assertJsonPath('cart.0.product_id', $product->id);
    }

    public function test_a_hold_keeps_no_price_and_the_list_subtotal_uses_its_price_list(): void
    {
        $user = $this->manager();
        $product = $this->product();

        $this->actingAs($user)->postJson('/pos/api/holds', [
            'cart' => [['product_id' => $product->id, 'qty' => 3]],
            'price_tier' => 'wholesale',
        ])->assertCreated();

        $this->assertArrayNotHasKey('unit_price', \App\Models\HeldSale::first()->cart[0]);

        $list = $this->actingAs($user)->getJson('/pos/api/holds')->assertOk()->json();
        $this->assertEqualsWithDelta(24.00, (float) $list[0]['subtotal'], 0.001); // 3 x 8.00
    }

    public function test_a_hold_defaults_to_retail_and_rejects_an_unknown_list(): void
    {
        $user = $this->manager();
        $product = $this->product();

        $this->actingAs($user)->postJson('/pos/api/holds', ['cart' => [['product_id' => $product->id, 'qty' => 1]]])
            ->assertCreated();
        $this->assertSame('retail', \App\Models\HeldSale::first()->price_tier);

        $this->actingAs($user)->postJson('/pos/api/holds', [
            'cart' => [['product_id' => $product->id, 'qty' => 1]], 'price_tier' => 'gold',
        ])->assertStatus(422)->assertJsonValidationErrors('price_tier');
    }

    public function test_the_receipt_names_the_price_list_only_when_it_is_not_retail(): void
    {
        $product = $this->product();
        $manager = $this->manager();

        $wholesale = $this->sell($manager, $product, ['price_tier' => 'wholesale'])->assertCreated()->json('sale.id');
        $retail = $this->sell($manager, $product)->assertCreated()->json('sale.id');

        $this->actingAs($manager)->get("/pos/receipts/{$wholesale}/print")
            ->assertOk()->assertSee('Price list:')->assertSee('Wholesale');
        $this->actingAs($manager)->get("/pos/receipts/{$retail}/print")
            ->assertOk()->assertDontSee('Price list:');
    }

    public function test_the_sell_screen_is_told_whether_this_user_may_change_the_list(): void
    {
        $this->withoutVite();
        Setting::set('cashier_can_change_price_tier', '0', 'pos', 'bool');

        $this->actingAs($this->userWithShift())->get('/pos')
            ->assertOk()->assertSee('canChangeTier: false', false);
        $this->actingAs($this->manager())->get('/pos')
            ->assertOk()->assertSee('canChangeTier: true', false);
    }

    public function test_the_settings_page_saves_the_cashier_price_list_switch(): void
    {
        $admin = User::factory()->admin()->create();

        // An unchecked checkbox is simply absent from the form post.
        $this->actingAs($admin)->post(route('settings.update'), [
            'group' => 'pos', 'settings' => ['max_cashier_discount_pct' => 10],
        ])->assertRedirect();
        $this->assertFalse((bool) Setting::get('cashier_can_change_price_tier', true));

        $this->actingAs($admin)->post(route('settings.update'), [
            'group' => 'pos', 'settings' => ['max_cashier_discount_pct' => 10, 'cashier_can_change_price_tier' => '1'],
        ])->assertRedirect();
        $this->assertTrue((bool) Setting::get('cashier_can_change_price_tier', false));
    }
}
