<?php

namespace Tests\Feature\Pos;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Line and cart discounts: who may give how much, which products refuse them,
 * and that a whole-cart discount is split so VAT, totals and reports still add up.
 */
class DiscountTest extends TestCase
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

    private function product(float $price = 10.00, array $attrs = []): Product
    {
        return Product::factory()->create($attrs + [
            'price_usd' => $price, 'cost_usd' => 1.00, 'stock_qty' => 100, 'is_taxable' => false,
        ]);
    }

    private function vat(bool $inclusive): Tax
    {
        return Tax::create([
            'name' => $inclusive ? 'VAT incl' : 'VAT', 'rate' => 0.11,
            'is_inclusive' => $inclusive, 'is_default' => !$inclusive, 'is_active' => true,
        ]);
    }

    private function sell(User $user, array $cart, array $extra = []): TestResponse
    {
        return $this->actingAs($user)->postJson('/pos/api/sales', $extra + [
            'cart' => $cart,
            'payment' => ['method' => 'cash_usd', 'amount_usd' => 1000],
        ]);
    }

    // --- who may give how much ------------------------------------------

    public function test_a_cashier_with_no_personal_cap_gets_the_store_default(): void
    {
        Setting::set('max_cashier_discount_pct', 10, 'pos', 'float');
        $cashier = $this->userWithShift(['max_discount_pct' => 0]);
        $product = $this->product();

        $this->sell($cashier, [['product_id' => $product->id, 'qty' => 1, 'discount_pct' => 10]])->assertCreated();

        $this->sell($cashier, [['product_id' => $product->id, 'qty' => 1, 'discount_pct' => 10.5]])
            ->assertStatus(422)->assertJsonStructure(['error' => ['cart.0.discount']]);

        $this->assertSame(1, Sale::count());
    }

    public function test_a_personal_cap_overrides_the_store_default_in_both_directions(): void
    {
        Setting::set('max_cashier_discount_pct', 10, 'pos', 'float');
        $product = $this->product();

        $strict = $this->userWithShift(['max_discount_pct' => 5]);
        $this->sell($strict, [['product_id' => $product->id, 'qty' => 1, 'discount_pct' => 5]])->assertCreated();
        $this->sell($strict, [['product_id' => $product->id, 'qty' => 1, 'discount_pct' => 6]])->assertStatus(422);

        $generous = $this->userWithShift(['max_discount_pct' => 20]);
        $this->sell($generous, [['product_id' => $product->id, 'qty' => 1, 'discount_pct' => 15]])->assertCreated();
    }

    public function test_managerial_users_are_not_capped(): void
    {
        Setting::set('max_cashier_discount_pct', 10, 'pos', 'float');
        $product = $this->product();

        $this->sell($this->manager(), [['product_id' => $product->id, 'qty' => 1, 'discount_pct' => 50]])->assertCreated();
        $this->sell($this->userWithShift(['role' => User::ROLE_ADMIN]), [['product_id' => $product->id, 'qty' => 1, 'discount_pct' => 100]])
            ->assertCreated();
    }

    public function test_a_fixed_amount_is_judged_by_its_effective_percentage(): void
    {
        Setting::set('max_cashier_discount_pct', 10, 'pos', 'float');
        $cashier = $this->userWithShift();
        $product = $this->product(10.00);

        // $2 off a $10 line is 20%.
        $this->sell($cashier, [['product_id' => $product->id, 'qty' => 1, 'discount_amount' => 2.00]])->assertStatus(422);
        // $1 off is exactly 10%.
        $this->sell($cashier, [['product_id' => $product->id, 'qty' => 1, 'discount_amount' => 1.00]])->assertCreated();
        // Quantity counts: $2 off 2 x $10 is again 10%.
        $this->sell($cashier, [['product_id' => $product->id, 'qty' => 2, 'discount_amount' => 2.00]])->assertCreated();
    }

    public function test_a_discount_cannot_exceed_the_line_it_is_on(): void
    {
        $this->sell($this->manager(), [['product_id' => $this->product(10)->id, 'qty' => 1, 'discount_amount' => 15.00]])
            ->assertStatus(422)->assertJsonStructure(['error' => ['cart.0.discount']]);
        $this->assertSame(0, Sale::count());
    }

    public function test_a_product_that_does_not_allow_discounts_refuses_one_for_everybody(): void
    {
        $product = $this->product(10, ['allow_discount' => false]);

        $this->sell($this->manager(), [['product_id' => $product->id, 'qty' => 1, 'discount_pct' => 5]])
            ->assertStatus(422)->assertJsonStructure(['error' => ['cart.0.discount']]);
        $this->sell($this->manager(), [['product_id' => $product->id, 'qty' => 1, 'discount_amount' => 1]])
            ->assertStatus(422);

        // No discount, no problem.
        $this->sell($this->manager(), [['product_id' => $product->id, 'qty' => 1]])->assertCreated();
    }

    // --- cart discount -----------------------------------------------------

    public function test_a_cart_discount_skips_products_that_refuse_discounts_and_the_rest_absorb_it(): void
    {
        $open = $this->product(10.00);
        $locked = $this->product(10.00, ['allow_discount' => false]);

        $saleId = $this->sell($this->manager(), [
            ['product_id' => $open->id, 'qty' => 1],
            ['product_id' => $locked->id, 'qty' => 1],
        ], ['cart_discount_pct' => 10])->assertCreated()->json('sale.id');

        $sale = Sale::with('items')->findOrFail($saleId);
        $byProduct = $sale->items->keyBy('product_id');

        // 10% of the discountable $10 only = $1.00, all of it on the open line.
        $this->assertEqualsWithDelta(1.00, (float) $byProduct[$open->id]->discount_amount_usd, 0.0001);
        $this->assertEqualsWithDelta(0.00, (float) $byProduct[$locked->id]->discount_amount_usd, 0.0001);
        $this->assertEqualsWithDelta(1.00, (float) $sale->cart_discount_usd, 0.0001);
        $this->assertEqualsWithDelta(19.00, (float) $sale->total_usd, 0.0001);
    }

    public function test_a_cart_discount_across_inclusive_and_exclusive_vat_lines_adds_up(): void
    {
        $exclusive = $this->product(100.00, ['tax_id' => $this->vat(false)->id, 'is_taxable' => true]);
        $inclusive = $this->product(111.00, ['tax_id' => $this->vat(true)->id, 'is_taxable' => true]);

        $saleId = $this->sell($this->manager(), [
            ['product_id' => $exclusive->id, 'qty' => 1],
            ['product_id' => $inclusive->id, 'qty' => 1],
        ], ['cart_discount_pct' => 10])->assertCreated()->json('sale.id');

        $sale = Sale::with('items')->findOrFail($saleId);

        // Discountable net 211.00 -> 10% = 21.10, split 10.00 / 11.10 by each line's net.
        //   exclusive: 100 - 10.00 = 90.00 + 9.90 VAT      = 99.90
        //   inclusive: 111 - 11.10 = 99.90 (VAT 9.90 inside) = 99.90
        $this->assertEqualsWithDelta(211.00, (float) $sale->subtotal_usd, 0.0001);
        $this->assertEqualsWithDelta(21.10, (float) $sale->items->sum('discount_amount_usd'), 0.0001);
        $this->assertEqualsWithDelta(21.10, (float) $sale->discount_amount_usd, 0.0001);
        $this->assertEqualsWithDelta(21.10, (float) $sale->cart_discount_usd, 0.0001);
        $this->assertEqualsWithDelta(19.80, (float) $sale->tax_amount_usd, 0.0001);
        $this->assertEqualsWithDelta(199.80, (float) $sale->total_usd, 0.0001);
        $this->assertEqualsWithDelta((float) $sale->total_usd, (float) $sale->items->sum('line_total_usd'), 0.0001);
    }

    public function test_ten_dollars_over_three_equal_lines_sums_to_exactly_ten(): void
    {
        $cart = array_map(fn () => ['product_id' => $this->product(10.00)->id, 'qty' => 1], range(1, 3));

        $saleId = $this->sell($this->manager(), $cart, ['cart_discount_amount' => 10.00])
            ->assertCreated()->json('sale.id');

        $sale = Sale::with('items')->findOrFail($saleId);
        $shares = $sale->items->pluck('discount_amount_usd')->map(fn ($v) => (float) $v)->all();

        // 3.3333 x 2 + the remainder (3.3334) on the largest (first) line.
        $this->assertEqualsWithDelta(10.0000, array_sum($shares), 0.000001);
        $this->assertEqualsWithDelta(3.3334, $shares[0], 0.000001);
        $this->assertEqualsWithDelta(10.0000, (float) $sale->cart_discount_usd, 0.000001);
        $this->assertEqualsWithDelta(20.00, (float) $sale->total_usd, 0.0001);
    }

    public function test_a_cart_discount_larger_than_the_discountable_total_is_refused(): void
    {
        $this->sell($this->manager(), [['product_id' => $this->product(10)->id, 'qty' => 1]], ['cart_discount_amount' => 50])
            ->assertStatus(422)->assertJsonStructure(['error' => ['cart_discount']]);

        // Every product refuses discounts: a flat amount has nothing to come off...
        $locked = $this->product(10, ['allow_discount' => false]);
        $this->sell($this->manager(), [['product_id' => $locked->id, 'qty' => 1]], ['cart_discount_amount' => 5])
            ->assertStatus(422);
        $this->assertSame(0, Sale::count());

        // ...while a percentage of nothing is just nothing: full price, no error.
        $saleId = $this->sell($this->manager(), [['product_id' => $locked->id, 'qty' => 1]], ['cart_discount_pct' => 5])
            ->assertCreated()->json('sale.id');
        $sale = Sale::findOrFail($saleId);
        $this->assertEqualsWithDelta(10.00, (float) $sale->total_usd, 0.0001);
        $this->assertEqualsWithDelta(0.00, (float) $sale->cart_discount_usd, 0.0001);
    }

    public function test_a_cashiers_limit_covers_line_discount_and_cart_share_together(): void
    {
        $cashier = $this->userWithShift(['max_discount_pct' => 10]);
        $product = $this->product(10.00);
        $line = fn (array $extra = []) => [['product_id' => $product->id, 'qty' => 1] + $extra];

        $this->sell($cashier, $line(), ['cart_discount_pct' => 10])->assertCreated();
        $this->sell($cashier, $line(), ['cart_discount_pct' => 10.5])->assertStatus(422);
        // 6% on the line, then 5% of what is left: 10.7% overall.
        $this->sell($cashier, $line(['discount_pct' => 6]), ['cart_discount_pct' => 5])->assertStatus(422);
        // 5% then 5% of the rest is 9.75%: inside the limit.
        $this->sell($cashier, $line(['discount_pct' => 5]), ['cart_discount_pct' => 5])->assertCreated();
    }

    // --- tax-exempt ----------------------------------------------------------

    public function test_a_tax_exempt_customer_is_charged_no_tax_on_any_line(): void
    {
        $vat = $this->vat(false);
        $a = $this->product(100.00, ['tax_id' => $vat->id, 'is_taxable' => true]);
        $b = $this->product(50.00, ['tax_id' => $vat->id, 'is_taxable' => true]);
        $exempt = Customer::create(['name' => 'Embassy', 'customer_group' => 'retail', 'tax_exempt' => true, 'is_active' => true]);
        $cart = [['product_id' => $a->id, 'qty' => 1], ['product_id' => $b->id, 'qty' => 2]];

        $response = $this->sell($this->manager(), $cart, ['customer_id' => $exempt->id])->assertCreated();

        $this->assertEqualsWithDelta(200.00, (float) $response->json('sale.total_usd'), 0.0001);
        $this->assertEqualsWithDelta(0.00, (float) $response->json('sale.tax_amount_usd'), 0.0001);
        foreach (Sale::with('items')->find($response->json('sale.id'))->items as $item) {
            $this->assertEqualsWithDelta(0.0, (float) $item->tax_amount_usd, 0.0001);
            $this->assertEqualsWithDelta(0.0, (float) $item->tax_rate, 0.0001);
        }

        // Control: the same cart for an ordinary customer carries the 11%.
        $taxed = $this->sell($this->manager(), $cart)->assertCreated();
        $this->assertEqualsWithDelta(222.00, (float) $taxed->json('sale.total_usd'), 0.0001);
    }

    // --- records ---------------------------------------------------------------

    public function test_the_sale_audit_entry_records_discount_use(): void
    {
        $product = $this->product(20.00);

        $saleId = $this->sell($this->manager(), [['product_id' => $product->id, 'qty' => 1, 'discount_amount' => 2.00]], ['cart_discount_pct' => 10])
            ->assertCreated()->json('sale.id');

        $new = AuditLog::where('action', 'sale')->where('model_id', $saleId)->firstOrFail()->new_values;
        $this->assertSame('retail', $new['price_tier']);
        $this->assertEqualsWithDelta(3.80, $new['discount_total'], 0.0001);   // 2.00 line + 1.80 cart
        $this->assertEqualsWithDelta(1.80, $new['cart_discount'], 0.0001);
        $this->assertEqualsWithDelta(19.00, $new['max_line_discount_pct'], 0.01); // 3.80 / 20.00
    }

    public function test_the_receipt_splits_item_and_cart_discounts(): void
    {
        $manager = $this->manager();
        $product = $this->product(20.00);

        $withCart = $this->sell($manager, [['product_id' => $product->id, 'qty' => 1, 'discount_amount' => 2.00]], ['cart_discount_pct' => 10])
            ->assertCreated()->json('sale.id');
        $itemOnly = $this->sell($manager, [['product_id' => $product->id, 'qty' => 1, 'discount_amount' => 2.00]])
            ->assertCreated()->json('sale.id');

        // 2.00 item + 1.80 cart: the two rows add up to the 3.80 in the line.
        $this->actingAs($manager)->get("/pos/receipts/{$withCart}/print")->assertOk()
            ->assertSee('Item discounts')->assertSee('-$2.00')
            ->assertSee('Cart discount')->assertSee('-$1.80')
            ->assertSee('disc: -$3.80');

        // No cart discount: the single "Discount" row, as before.
        $this->actingAs($manager)->get("/pos/receipts/{$itemOnly}/print")->assertOk()
            ->assertSee('Discount')->assertDontSee('Cart discount')->assertDontSee('Item discounts');
    }

    // --- limits and cost data ---------------------------------------------------

    public function test_max_discount_pct_has_one_rule_for_every_caller(): void
    {
        Setting::set('max_cashier_discount_pct', 12, 'pos', 'float');

        $this->assertSame(100.0, User::factory()->manager()->make()->maxDiscountPct());
        $this->assertSame(100.0, User::factory()->admin()->make()->maxDiscountPct());
        $this->assertSame(7.5, User::factory()->make(['max_discount_pct' => 7.5])->maxDiscountPct());
        $this->assertSame(12.0, User::factory()->make(['max_discount_pct' => 0])->maxDiscountPct()); // store default
    }

    public function test_the_store_default_is_the_config_value_until_a_setting_exists(): void
    {
        $this->assertSame(
            (float) config('pos.pos.max_cashier_discount_pct'),
            User::factory()->make(['max_discount_pct' => 0])->maxDiscountPct()
        );
    }

    public function test_cashiers_are_never_sent_cost_but_managers_are(): void
    {
        $this->withoutVite();
        $product = $this->product(10.00, ['cost_usd' => 7.3456, 'barcode' => '9990001112223']);

        $this->actingAs($this->userWithShift())->get('/pos')->assertOk()->assertDontSee('7.3456');
        $this->actingAs($this->userWithShift())->getJson('/pos/api/barcode?code=9990001112223')
            ->assertOk()->assertJsonMissingPath('cost_usd');
        $this->actingAs($this->userWithShift())->getJson('/pos/api/products')
            ->assertOk()->assertJsonMissingPath('0.cost_usd');

        $this->actingAs($this->manager())->get('/pos')->assertOk()->assertSee('7.3456');
        $this->actingAs($this->manager())->getJson('/pos/api/barcode?code=9990001112223')
            ->assertOk()->assertJsonPath('id', $product->id)->assertJsonPath('cost_usd', '7.3456');
    }

    public function test_the_user_form_explains_that_zero_means_the_store_default(): void
    {
        $this->actingAs(User::factory()->admin()->create())->get(route('users.create'))
            ->assertOk()->assertSee('0 = use the store default from Settings');
    }
}
