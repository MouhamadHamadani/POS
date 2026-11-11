<?php

namespace Tests\Feature\Products;

use App\Models\Category;
use App\Models\HeldSale;
use App\Models\InventoryAdjustment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ReturnTransaction;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\User;
use App\Services\InventoryService;
use App\Services\ReportService;
use App\Services\SaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Selling, voiding, returning and holding a variant. A variant is sold at the product's price, from its own
 * stock; products.stock_qty (the total) and the main item's derived count move in step.
 */
class ProductVariantSalesTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;
    private ProductVariant $red;
    private ProductVariant $blue;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::set('exchange_rate', 90000, 'currency', 'float');
        Setting::set('lbp_rounding_step', 1000, 'currency', 'int');

        // Main item 10, Red 6, Blue 4: the product total is 20.
        $this->product = Product::factory()->create([
            'name' => 'Soap', 'category_id' => Category::factory()->create()->id,
            'price_usd' => 10, 'wholesale_price_usd' => 8, 'vip_price_usd' => 6, 'cost_usd' => 4,
            'stock_qty' => 10, 'is_taxable' => false,
        ]);
        $this->red = ProductVariantsTest::variantWithStock($this->product, '8000000000001', 6, ['label' => 'Red']);
        $this->blue = ProductVariantsTest::variantWithStock($this->product, '8000000000002', 4, ['label' => 'Blue']);
    }

    private function till(): User
    {
        $cashier = User::factory()->create(['role' => User::ROLE_CASHIER, 'max_discount_pct' => 100]);
        Shift::create(['user_id' => $cashier->id, 'opened_at' => now(), 'opening_cash_usd' => 0, 'opening_cash_lbp' => 0, 'status' => Shift::STATUS_OPEN]);

        return $cashier;
    }

    private function sell(array $cart, array $extra = [], ?User $by = null)
    {
        return $this->actingAs($by ?? $this->till())->postJson('/pos/api/sales', $extra + [
            'cart' => $cart,
            'payment' => ['amount_usd' => 1000],
        ]);
    }

    private function line(?ProductVariant $variant, float $qty = 1): array
    {
        return ['product_id' => $this->product->id, 'product_variant_id' => $variant?->id, 'qty' => $qty];
    }

    private function counts(): array
    {
        $p = $this->product->fresh();

        return [
            'total' => (float) $p->stock_qty,
            'main' => $p->mainStockQty(),
            'red' => (float) $this->red->fresh()->stock_qty,
            'blue' => (float) $this->blue->fresh()->stock_qty,
        ];
    }

    public function test_a_variant_is_sold_at_the_products_price_from_its_own_stock(): void
    {
        $this->sell([$this->line($this->red, 2)])->assertCreated();

        $item = Sale::sole()->items->sole();
        $this->assertSame($this->red->id, $item->product_variant_id);
        $this->assertEquals(10, (float) $item->unit_price_usd);
        $this->assertEquals(20, (float) $item->line_total_usd);
        $this->assertEquals(4, (float) $item->cost_usd); // the product's cost
        $this->assertSame(['total' => 18.0, 'main' => 10.0, 'red' => 4.0, 'blue' => 4.0], $this->counts());

        $adjustment = InventoryAdjustment::where('type', InventoryAdjustment::TYPE_SALE)->sole();
        $this->assertSame($this->red->id, $adjustment->product_variant_id);
        $this->assertEquals(-2, (float) $adjustment->qty_change);
        $this->assertEquals(18, (float) $adjustment->qty_after); // the product total, so the product log still chains
    }

    public function test_the_line_snapshots_the_variant_name_and_sku_and_keeps_them_when_the_label_changes(): void
    {
        $this->red->update(['sku' => 'SOAP-RED']);
        $this->sell([$this->line($this->red)])->assertCreated();

        $this->red->update(['label' => 'Crimson', 'sku' => 'SOAP-CRIMSON']);

        $item = Sale::sole()->items->sole();
        $this->assertSame('Soap — Red', $item->product_name);
        $this->assertSame('SOAP-RED', $item->product_sku);
    }

    public function test_an_unlabelled_variant_is_told_apart_by_the_end_of_its_barcode(): void
    {
        $plain = ProductVariantsTest::variantWithStock($this->product, '8000000000099', 3, ['label' => null]);
        $this->sell([$this->line($plain)])->assertCreated();

        $this->assertSame('Soap (…0099)', Sale::sole()->items->sole()->product_name);
    }

    public function test_the_price_comes_from_the_product_for_each_tier(): void
    {
        Setting::set('require_shift', false, 'pos', 'bool');
        DB::table('product_variants')->where('id', $this->red->id)->update(['price_modifier' => 5]);
        $manager = User::factory()->manager()->create();

        $paid = [];
        foreach (['retail' => 10, 'wholesale' => 8, 'vip' => 6] as $tier => $price) {
            $cart = [$this->line($this->red) + ['unit_price' => 1, 'price' => 1]];
            $this->sell($cart, ['price_tier' => $tier], $manager)->assertCreated();
            $paid[$tier] = (float) Sale::latest('id')->first()->items->sole()->unit_price_usd;
        }

        $this->assertSame(['retail' => 10.0, 'wholesale' => 8.0, 'vip' => 6.0], $paid);
    }

    public function test_selling_more_than_a_variant_holds_is_refused_even_when_the_product_has_stock(): void
    {
        // The product has 20 in total and Blue has 4: five Blue is still too many.
        $this->sell([$this->line($this->blue, 5)])->assertStatus(422)->assertJsonPath('error.stock.0', fn ($m) => str_contains($m, 'Soap — Blue'));

        $this->assertSame(0, Sale::count());
        $this->assertSame(['total' => 20.0, 'main' => 10.0, 'red' => 6.0, 'blue' => 4.0], $this->counts());
    }

    public function test_the_main_item_is_checked_against_what_the_variants_leave_not_the_whole_total(): void
    {
        // Main item 10: eleven is refused although 20 are on the shelf in all.
        $this->sell([$this->line(null, 11)])->assertStatus(422);
        $this->assertSame(0, Sale::count());

        $this->sell([$this->line(null, 10)])->assertCreated();
        $this->assertSame(['total' => 10.0, 'main' => 0.0, 'red' => 6.0, 'blue' => 4.0], $this->counts());
    }

    public function test_two_lines_of_the_same_variant_are_checked_together(): void
    {
        $this->sell([$this->line($this->blue, 3), $this->line($this->blue, 2)])->assertStatus(422);
        $this->assertSame(0, Sale::count());
    }

    public function test_a_mixed_cart_makes_one_line_per_item_and_moves_each_count(): void
    {
        $this->sell([$this->line(null, 2), $this->line($this->red, 1), $this->line($this->blue, 3)])->assertCreated();

        $items = Sale::sole()->items;
        $this->assertCount(3, $items);
        $this->assertSame([null, $this->red->id, $this->blue->id], $items->pluck('product_variant_id')->all());
        $this->assertSame(['total' => 14.0, 'main' => 8.0, 'red' => 5.0, 'blue' => 1.0], $this->counts());
    }

    public function test_a_variant_of_another_product_or_a_missing_one_is_refused(): void
    {
        $other = Product::factory()->create(['stock_qty' => 5]);
        $theirs = ProductVariantsTest::variantWithStock($other, '8000000000050', 5);

        $this->sell([$this->line($theirs)])->assertStatus(422);
        $this->sell([['product_id' => $this->product->id, 'product_variant_id' => 99999, 'qty' => 1]])->assertStatus(422);
        $this->assertSame(0, Sale::count());
    }

    public function test_an_inactive_variant_cannot_be_sold_and_keeps_its_barcode(): void
    {
        $this->red->update(['is_active' => false]);

        $this->sell([$this->line($this->red)])->assertStatus(422);
        $this->assertSame(0, Sale::count());
        $this->assertSame('8000000000001', $this->red->fresh()->barcode);
        $this->assertEquals(6, (float) $this->red->fresh()->stock_qty);
    }

    public function test_a_void_puts_the_stock_back_on_the_variant(): void
    {
        $this->sell([$this->line($this->red, 2), $this->line(null, 1)])->assertCreated();
        $this->assertSame(['total' => 17.0, 'main' => 9.0, 'red' => 4.0, 'blue' => 4.0], $this->counts());

        app(SaleService::class)->voidSale(Sale::sole(), User::factory()->manager()->create()->id, 'mistake');

        $this->assertSame(['total' => 20.0, 'main' => 10.0, 'red' => 6.0, 'blue' => 4.0], $this->counts());
        $this->assertSame($this->red->id, InventoryAdjustment::where('reason', 'sale_void')->whereNotNull('product_variant_id')->sole()->product_variant_id);
    }

    public function test_an_approved_return_restocks_the_variant_it_was_sold_from(): void
    {
        $manager = User::factory()->manager()->create();
        Shift::create(['user_id' => $manager->id, 'opened_at' => now(), 'opening_cash_usd' => 0, 'opening_cash_lbp' => 0, 'status' => Shift::STATUS_OPEN]);
        $this->sell([$this->line($this->blue, 3)], by: $manager)->assertCreated();
        $sale = Sale::sole();
        $line = $sale->items->sole();

        $this->actingAs($manager)->post(route('returns.store', $sale), [
            'items' => [['sale_item_id' => $line->id, 'qty_returned' => 2, 'restock' => 1]],
            'reason' => 'other', 'refund_method' => 'cash_usd',
        ])->assertSessionHasNoErrors();

        $this->assertSame(ReturnTransaction::STATUS_COMPLETED, ReturnTransaction::sole()->status);
        $this->assertSame(['total' => 19.0, 'main' => 10.0, 'red' => 6.0, 'blue' => 3.0], $this->counts());
        $this->assertEquals(20.0, (float) ReturnTransaction::sole()->refund_amount_usd); // refund maths: 2 x $10
        $this->assertSame($this->blue->id, InventoryAdjustment::where('type', InventoryAdjustment::TYPE_RETURN)->sole()->product_variant_id);
    }

    public function test_a_product_without_variants_sells_exactly_as_before(): void
    {
        $plain = Product::factory()->create(['price_usd' => 3, 'stock_qty' => 4, 'is_taxable' => false]);

        $this->sell([['product_id' => $plain->id, 'qty' => 4]])->assertCreated();
        $this->sell([['product_id' => $plain->id, 'qty' => 1]])->assertStatus(422);

        $item = Sale::sole()->items->sole();
        $this->assertNull($item->product_variant_id);
        $this->assertSame($plain->name, $item->product_name);
        $this->assertEquals(0, (float) $plain->fresh()->stock_qty);
        $this->assertNull(InventoryAdjustment::where('product_id', $plain->id)->sole()->product_variant_id);
    }

    // --- hold / recall ------------------------------------------------------------------------

    public function test_a_held_sale_carries_the_variant_and_recalls_it(): void
    {
        $user = $this->till();

        $id = $this->actingAs($user)->postJson('/pos/api/holds', ['cart' => [$this->line($this->red, 2), $this->line(null, 1)]])
            ->assertCreated()->json('id');
        $this->assertSame($this->red->id, HeldSale::find($id)->cart[0]['product_variant_id']);

        $recalled = $this->actingAs($user)->postJson("/pos/api/holds/{$id}/recall")->assertOk();
        $this->assertCount(2, $recalled->json('cart'));
        $this->assertSame($this->red->id, $recalled->json('cart.0.product_variant_id'));
        $this->assertSame([], $recalled->json('dropped'));
    }

    public function test_a_deleted_or_inactive_variant_is_dropped_on_recall_with_its_name(): void
    {
        $user = $this->till();
        $id = $this->actingAs($user)->postJson('/pos/api/holds', ['cart' => [
            $this->line($this->red), $this->line($this->blue), $this->line(null),
        ]])->json('id');

        $this->red->update(['is_active' => false]);
        $this->blue->update(['barcode' => null]);
        $this->blue->delete();

        $recalled = $this->actingAs($user)->postJson("/pos/api/holds/{$id}/recall")->assertOk();
        $this->assertCount(1, $recalled->json('cart'));
        $this->assertNull($recalled->json('cart.0.product_variant_id'));
        $this->assertCount(2, $recalled->json('dropped'));
        $this->assertContains('Soap — Red', $recalled->json('dropped'));
    }

    // --- the invariant -------------------------------------------------------------------------

    /** After any mix of sales, voids, returns, adjustments and receipts the ledgers still add up. */
    public function test_the_stock_invariant_survives_a_random_mix_of_movements(): void
    {
        mt_srand(20261112);
        $inventory = app(InventoryService::class);
        $manager = User::factory()->manager()->create();
        Shift::create(['user_id' => $manager->id, 'opened_at' => now(), 'opening_cash_usd' => 0, 'opening_cash_lbp' => 0, 'status' => Shift::STATUS_OPEN]);
        $items = [null, $this->red, $this->blue];
        $sales = [];

        for ($i = 0; $i < 60; $i++) {
            $item = $items[mt_rand(0, 2)];
            $qty = mt_rand(1, 3);

            switch (mt_rand(0, 4)) {
                case 0: // sale (may be refused for stock; that must change nothing)
                    $r = $this->sell([$this->line($item, $qty)], by: $manager);
                    if ($r->status() === 201) {
                        $sales[] = Sale::latest('id')->first();
                    }
                    break;
                case 1: // void a random completed sale
                    $open = array_values(array_filter($sales, fn ($s) => $s->fresh()->status === Sale::STATUS_COMPLETED));
                    if ($open) {
                        app(SaleService::class)->voidSale($open[mt_rand(0, count($open) - 1)], $manager->id);
                    }
                    break;
                case 2: // goods received
                    $inventory->receiveStock($this->product->fresh(), $qty, $manager->id, 1, variant: $item);
                    break;
                case 3: // manual set to a count
                    $inventory->setStock($this->product->fresh(), mt_rand(0, 12), $manager->id, 'count', $item);
                    break;
                case 4: // manual removal (refused when it would go negative on a tracked item)
                    try {
                        $inventory->adjust($this->product->fresh(), -$qty, InventoryAdjustment::TYPE_REMOVE, $manager->id, reason: 'x', allowNegative: false, variant: $item);
                    } catch (\App\Exceptions\InsufficientStockException) {
                    }
                    break;
            }

            $c = $this->counts();
            $this->assertEqualsWithDelta($c['total'], $c['main'] + $c['red'] + $c['blue'], 0.0001, "step $i: total != main + variants");
            foreach (['main', 'red', 'blue'] as $k) {
                $this->assertGreaterThanOrEqual(-0.0001, $c[$k], "step $i: $k went negative");
            }
            // Each count is the sum of the movements written for that item (the main item started at 10 with no
            // movement row: the factory set it directly).
            $moved = fn (?int $variantId) => (float) DB::table('inventory_adjustments')->where('product_id', $this->product->id)
                ->when($variantId, fn ($q) => $q->where('product_variant_id', $variantId), fn ($q) => $q->whereNull('product_variant_id'))->sum('qty_change');
            $this->assertEqualsWithDelta($c['main'], 10 + $moved(null), 0.0001, "step $i: main item != its movements");
            $this->assertEqualsWithDelta($c['red'], $moved($this->red->id), 0.0001, "step $i: red != its movements");
            $this->assertEqualsWithDelta($c['blue'], $moved($this->blue->id), 0.0001, "step $i: blue != its movements");
        }

        // The product-level report identity is untouched: closing = opening + in - out = the stock on hand.
        $today = Carbon::today();
        $row = collect(app(ReportService::class)->inventoryMovement($today, $today)['rows'])->firstWhere('id', $this->product->id);
        $this->assertEqualsWithDelta($this->counts()['total'], $row['closing'], 0.0001);
        $this->assertEqualsWithDelta($row['opening'] + $row['qty_in'] - $row['qty_out'], $row['closing'], 0.0001);
    }
}
