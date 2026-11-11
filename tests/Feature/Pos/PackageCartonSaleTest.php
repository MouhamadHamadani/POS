<?php

namespace Tests\Feature\Pos;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\HeldSale;
use App\Models\InventoryAdjustment;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Shift;
use App\Models\User;
use App\Services\ReportService;
use App\Services\SaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pack / package / carton selling (client/tobacco, docs/packaging.md). Stock is counted in packs; a line keeps its
 * quantity in sale units with a snapshot of how many packs one unit is. The server decides unit, size and price.
 *
 * The product used here: a pack at $8, a package of 10 packs at $75 (wholesale $70, VIP not set, so it falls back to
 * $75), and a carton of 20 packages = 200 packs at $1400.
 */
class PackageCartonSaleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['pos.features.packaging' => true]);
    }

    private function admin(): User
    {
        $admin = User::factory()->admin()->create();
        Shift::create(['user_id' => $admin->id, 'opened_at' => now(), 'opening_cash_usd' => 0, 'opening_cash_lbp' => 0, 'status' => Shift::STATUS_OPEN]);

        return $admin;
    }

    private function tobacco(array $over = []): Product
    {
        return Product::factory()->create($over + [
            'name' => 'Marlboro Red', 'barcode' => '5000000000017',
            'price_usd' => 8, 'cost_usd' => 5, 'stock_qty' => 1000, 'is_taxable' => false,
            'has_package' => true, 'package_size' => 10,
            'package_price_usd' => 75, 'package_wholesale_price_usd' => 70, 'package_vip_price_usd' => null,
            'carton_barcode' => '5000000000024', 'packages_per_carton' => 20, 'sell_carton_at_pos' => false,
            'carton_price_usd' => 1400,
        ]);
    }

    private function sell(User $user, array $cart, array $extra = [])
    {
        return $this->actingAs($user)->postJson(route('pos.api.sales'), $extra + [
            'cart' => $cart,
            'payment' => ['amount_usd' => 5000],
            'price_tier' => 'retail',
        ]);
    }

    public function test_a_product_without_packaging_sells_exactly_as_before(): void
    {
        $admin = $this->admin();
        $plain = Product::factory()->create(['price_usd' => 4, 'cost_usd' => 2, 'stock_qty' => 20, 'is_taxable' => false]);

        $this->sell($admin, [['product_id' => $plain->id, 'qty' => 3]])->assertCreated();

        $item = SaleItem::sole();
        $this->assertSame('pack', $item->sale_unit);
        $this->assertEquals(1, $item->unit_multiplier);
        $this->assertEquals(3, $item->base_qty);
        $this->assertEquals(2, $item->cost_usd, 'the cost snapshot is the product cost, untouched');
        $this->assertEquals(12, $item->line_total_usd);
        $this->assertEquals(17, $plain->fresh()->stock_qty);
        $this->assertArrayNotHasKey('units', AuditLog::where('action', 'sale')->sole()->new_values, 'an all-pack sale audits as it always did');
    }

    public function test_a_package_line_moves_ten_packs_per_unit_and_prices_at_the_package_price(): void
    {
        $admin = $this->admin();
        $product = $this->tobacco();

        $this->sell($admin, [['product_id' => $product->id, 'qty' => 2, 'sale_unit' => 'package']])->assertCreated();

        $item = SaleItem::sole();
        $this->assertSame('package', $item->sale_unit);
        $this->assertEquals(10, $item->unit_multiplier);
        $this->assertEquals(2, $item->qty);
        $this->assertEquals(20, $item->base_qty);
        $this->assertEquals(75, $item->unit_price_usd);
        $this->assertEquals(150, $item->line_total_usd);
        $this->assertEquals(50, $item->cost_usd, 'cost of ONE sale unit (10 packs x $5), so qty x cost_usd stays the COGS');
        $this->assertEquals(980, $product->fresh()->stock_qty);

        $moves = InventoryAdjustment::where('product_id', $product->id)->where('type', InventoryAdjustment::TYPE_SALE)->get();
        $this->assertCount(1, $moves, 'one movement row per line');
        $this->assertEquals(-20, $moves->first()->qty_change);

        $units = AuditLog::where('action', 'sale')->sole()->new_values['units'];
        $this->assertSame('package', $units[0]['unit']);
        $this->assertEquals(20, $units[0]['packs']);
    }

    public function test_the_tier_picks_the_package_price_and_a_missing_tier_price_falls_back_to_the_package_retail_price(): void
    {
        $admin = $this->admin();
        $product = $this->tobacco();

        $this->sell($admin, [['product_id' => $product->id, 'qty' => 1, 'sale_unit' => 'package']], ['price_tier' => 'wholesale'])->assertCreated();
        $this->assertEquals(70, SaleItem::latest('id')->first()->unit_price_usd);

        $this->sell($admin, [['product_id' => $product->id, 'qty' => 1, 'sale_unit' => 'package']], ['price_tier' => 'vip'])->assertCreated();
        $this->assertEquals(75, SaleItem::latest('id')->first()->unit_price_usd, 'VIP package price is null: the package retail price');
    }

    public function test_a_posted_price_or_multiplier_is_ignored(): void
    {
        $admin = $this->admin();
        $product = $this->tobacco();

        $this->sell($admin, [[
            'product_id' => $product->id, 'qty' => 1, 'sale_unit' => 'package',
            'unit_price' => 0.01, 'unit_price_usd' => 0.01, 'unit_multiplier' => 1, 'base_qty' => 1,
        ]])->assertCreated();

        $item = SaleItem::sole();
        $this->assertEquals(75, $item->unit_price_usd);
        $this->assertEquals(10, $item->unit_multiplier);
        $this->assertEquals(10, $item->base_qty);
    }

    public function test_a_package_of_a_product_without_one_is_refused_and_nothing_is_written(): void
    {
        $admin = $this->admin();
        $plain = Product::factory()->create(['stock_qty' => 50, 'is_taxable' => false]);

        $this->sell($admin, [['product_id' => $plain->id, 'qty' => 1, 'sale_unit' => 'package']])
            ->assertStatus(422)->assertJsonValidationErrorFor('cart.0.sale_unit', 'error');

        $this->assertSame(0, Sale::count());
        $this->assertEquals(50, $plain->fresh()->stock_qty);
    }

    public function test_a_unit_that_is_not_pack_package_or_carton_is_a_validation_error(): void
    {
        $admin = $this->admin();
        $product = $this->tobacco();

        $this->sell($admin, [['product_id' => $product->id, 'qty' => 1, 'sale_unit' => 'pallet']])->assertStatus(422);
        $this->assertSame(0, Sale::count());
    }

    public function test_a_carton_is_refused_while_the_product_does_not_sell_cartons_at_the_pos(): void
    {
        $admin = $this->admin();
        $product = $this->tobacco(['sell_carton_at_pos' => false]);

        $this->sell($admin, [['product_id' => $product->id, 'qty' => 1, 'sale_unit' => 'carton']])->assertStatus(422);

        $this->assertSame(0, Sale::count());
        $this->assertEquals(1000, $product->fresh()->stock_qty);
    }

    public function test_a_carton_sells_as_packages_times_packs_when_the_switch_is_on(): void
    {
        $admin = $this->admin();
        $product = $this->tobacco(['sell_carton_at_pos' => true]);

        $this->sell($admin, [['product_id' => $product->id, 'qty' => 2, 'sale_unit' => 'carton']])->assertCreated();

        $item = SaleItem::sole();
        $this->assertEquals(200, $item->unit_multiplier);
        $this->assertEquals(400, $item->base_qty);
        $this->assertEquals(2800, $item->line_total_usd);
        $this->assertEquals(600, $product->fresh()->stock_qty);
    }

    public function test_a_pack_line_and_a_package_line_of_one_product_are_two_lines_drawing_on_one_stock(): void
    {
        $admin = $this->admin();
        $product = $this->tobacco();

        $this->sell($admin, [
            ['product_id' => $product->id, 'qty' => 1, 'sale_unit' => 'pack'],
            ['product_id' => $product->id, 'qty' => 1, 'sale_unit' => 'package'],
        ])->assertCreated();

        $this->assertSame(2, SaleItem::count());
        $this->assertEquals(8 + 75, (float) Sale::sole()->total_usd);
        $this->assertEquals(989, $product->fresh()->stock_qty);
    }

    public function test_two_lines_together_cannot_oversell_the_stock(): void
    {
        $admin = $this->admin();
        $product = $this->tobacco(['stock_qty' => 15]);

        // 6 packs + 1 package = 16 packs against 15 in stock: each line alone would fit.
        $this->sell($admin, [
            ['product_id' => $product->id, 'qty' => 6, 'sale_unit' => 'pack'],
            ['product_id' => $product->id, 'qty' => 1, 'sale_unit' => 'package'],
        ])->assertStatus(422);

        $this->assertSame(0, Sale::count());
        $this->assertEquals(15, $product->fresh()->stock_qty);
    }

    public function test_with_the_flag_off_a_package_is_refused_and_a_packaged_product_sells_as_packs(): void
    {
        config(['pos.features.packaging' => false]);
        $admin = $this->admin();
        $product = $this->tobacco();

        $this->sell($admin, [['product_id' => $product->id, 'qty' => 1, 'sale_unit' => 'package']])->assertStatus(422);
        $this->sell($admin, [['product_id' => $product->id, 'qty' => 2]])->assertCreated();

        $this->assertEquals(16, (float) Sale::sole()->total_usd);
        $this->assertEquals(998, $product->fresh()->stock_qty);
        $this->assertSame(['pack'], $product->fresh()->receivableUnits());
    }

    public function test_returning_one_package_restocks_ten_packs_and_refunds_the_line_total_over_the_quantity(): void
    {
        $admin = $this->admin();
        $product = $this->tobacco();
        $this->sell($admin, [['product_id' => $product->id, 'qty' => 2, 'sale_unit' => 'package']])->assertCreated();
        $sale = Sale::sole();
        $line = SaleItem::sole();

        $this->actingAs($admin)->post(route('returns.store', $sale), [
            'items' => [['sale_item_id' => $line->id, 'qty_returned' => 1, 'restock' => 1]],
            'reason' => 'other', 'refund_method' => 'cash_usd',
        ])->assertSessionHasNoErrors();

        $this->assertEquals(990, $product->fresh()->stock_qty, '980 after the sale + 10 packs back');
        $this->assertEquals(75, (float) \App\Models\ReturnTransaction::sole()->refund_amount_usd);
        $this->assertEquals(1, $line->fresh()->returned_qty);

        // One package is left to return; asking for two is refused and moves nothing.
        $this->actingAs($admin)->post(route('returns.store', $sale), [
            'items' => [['sale_item_id' => $line->id, 'qty_returned' => 2, 'restock' => 1]],
            'reason' => 'other', 'refund_method' => 'cash_usd',
        ])->assertSessionHasErrors('items');
        $this->assertEquals(990, $product->fresh()->stock_qty);
    }

    public function test_voiding_a_sale_restocks_the_packs_not_the_sale_units(): void
    {
        $admin = $this->admin();
        $product = $this->tobacco();
        $this->sell($admin, [
            ['product_id' => $product->id, 'qty' => 2, 'sale_unit' => 'package'],
            ['product_id' => $product->id, 'qty' => 3, 'sale_unit' => 'pack'],
        ])->assertCreated();
        $this->assertEquals(977, $product->fresh()->stock_qty);

        app(SaleService::class)->voidSale(Sale::sole(), $admin->id, 'test');

        $this->assertEquals(1000, $product->fresh()->stock_qty);
    }

    public function test_a_held_sale_keeps_the_unit_of_each_line(): void
    {
        $admin = $this->admin();
        $product = $this->tobacco();

        $this->actingAs($admin)->postJson(route('pos.api.holds.store'), [
            'cart' => [
                ['product_id' => $product->id, 'qty' => 2, 'sale_unit' => 'package'],
                ['product_id' => $product->id, 'qty' => 1, 'sale_unit' => 'pack'],
            ],
        ])->assertCreated();

        $held = HeldSale::sole();
        $this->assertSame(['package', 'pack'], array_column($held->cart, 'sale_unit'));

        $list = $this->actingAs($admin)->getJson(route('pos.api.holds.index'))->assertOk()->json();
        $this->assertEquals(2 * 75 + 8, $list[0]['subtotal'], 'the list prices a package line at the package price');

        $recall = $this->actingAs($admin)->postJson(route('pos.api.holds.recall', $held))->assertOk()->json('cart');
        $this->assertSame(['package', 'pack'], array_column($recall, 'sale_unit'));
    }

    public function test_the_stock_movements_still_add_up_with_package_sales_in_the_mix(): void
    {
        $admin = $this->admin();
        $product = $this->tobacco(['stock_qty' => 0]);
        app(\App\Services\InventoryService::class)->recordOpening($product->fresh(), 500, $admin->id);

        $this->sell($admin, [['product_id' => $product->id, 'qty' => 3, 'sale_unit' => 'package']])->assertCreated();
        $this->sell($admin, [['product_id' => $product->id, 'qty' => 4, 'sale_unit' => 'pack']])->assertCreated();

        $sum = (float) InventoryAdjustment::where('product_id', $product->id)->sum('qty_change');
        $this->assertEquals((float) $product->fresh()->stock_qty, $sum, 'closing stock equals the sum of every movement, in packs');
        $this->assertEquals(466, $sum);
    }

    public function test_sales_by_product_counts_packs_and_splits_out_the_packaged_units(): void
    {
        $admin = $this->admin();
        $product = $this->tobacco(['sell_carton_at_pos' => true]);
        $this->sell($admin, [
            ['product_id' => $product->id, 'qty' => 2, 'sale_unit' => 'package'],
            ['product_id' => $product->id, 'qty' => 5, 'sale_unit' => 'pack'],
            ['product_id' => $product->id, 'qty' => 1, 'sale_unit' => 'carton'],
        ])->assertCreated();

        $row = app(ReportService::class)->salesByProduct(now()->subDay(), now()->addDay())['rows'][0];

        $this->assertEquals(225, $row['units'], '2x10 + 5 + 200 packs');
        $this->assertEquals(2, $row['packages']);
        $this->assertEquals(1, $row['cartons']);
        // Revenue 150 + 40 + 1400; COGS is qty x unit cost: 2x50 + 5x5 + 1x1000.
        $this->assertEquals(1590 - 1125, $row['profit']);
    }

    public function test_the_dashboard_total_of_items_sold_counts_packs(): void
    {
        $admin = $this->admin();
        $product = $this->tobacco();
        $this->sell($admin, [['product_id' => $product->id, 'qty' => 2, 'sale_unit' => 'package']])->assertCreated();

        $this->assertEquals(20, (float) SaleItem::sum('base_qty'));
    }

    public function test_the_receipt_names_the_unit_and_a_plain_receipt_has_no_unit_text(): void
    {
        $admin = $this->admin();
        $product = $this->tobacco();
        $plain = Product::factory()->create(['name' => 'Plain Gum', 'price_usd' => 1, 'stock_qty' => 9, 'is_taxable' => false]);
        $this->sell($admin, [
            ['product_id' => $product->id, 'qty' => 1, 'sale_unit' => 'package'],
            ['product_id' => $plain->id, 'qty' => 1],
        ])->assertCreated();
        $sale = Sale::sole();

        $html = $this->actingAs($admin)->get("/pos/receipts/{$sale->id}/print")->assertOk()->getContent();
        $this->assertStringContainsString('Marlboro Red', $html);
        $this->assertStringContainsString('Package ×10', $html);
        $this->assertStringNotContainsString('Plain Gum —', $html);
    }

    public function test_the_till_gets_the_packaging_fields_only_when_the_flag_is_on_and_never_the_cost_for_a_cashier(): void
    {
        $this->tobacco();
        $cashier = User::factory()->create(['role' => User::ROLE_CASHIER]);
        Shift::create(['user_id' => $cashier->id, 'opened_at' => now(), 'opening_cash_usd' => 0, 'opening_cash_lbp' => 0, 'status' => Shift::STATUS_OPEN]);

        $on = $this->actingAs($cashier)->get(route('pos.sell'))->assertOk()->getContent();
        $this->assertStringContainsString('"package_price_usd":"75.0000"', $on, 'the product data carries the package price');
        $this->assertStringContainsString('aria-modal="true" :aria-label="pkgT', $on, 'the unit question is on the page');
        $this->assertStringNotContainsString('cost_usd', substr($on, strpos($on, 'allProducts:'), 4000), 'a cashier is not sent cost');

        config(['pos.features.packaging' => false]);
        $off = $this->actingAs($cashier)->get(route('pos.sell'))->assertOk()->getContent();
        $this->assertStringNotContainsString('"package_price_usd"', $off, 'no packaging data reaches a plain build');
        $this->assertStringNotContainsString('aria-modal="true" :aria-label="pkgT', $off, 'no unit modal markup');
    }

    public function test_existing_rows_get_their_quantity_as_base_qty_when_the_migration_runs(): void
    {
        \Illuminate\Support\Facades\Schema::dropColumns('sale_items', ['sale_unit', 'unit_multiplier', 'base_qty']);
        $admin = $this->admin();
        $product = $this->tobacco();
        $sale = Sale::create([
            'receipt_number' => 'OLD-1', 'user_id' => $admin->id, 'subtotal_usd' => 8, 'tax_amount_usd' => 0, 'total_usd' => 8,
            'total_lbp' => 0, 'exchange_rate' => 90000, 'payment_method' => Sale::METHOD_CASH_USD, 'status' => Sale::STATUS_COMPLETED,
        ]);
        \Illuminate\Support\Facades\DB::table('sale_items')->insert([
            'sale_id' => $sale->id, 'product_id' => $product->id, 'product_name' => 'Old', 'qty' => 3.5,
            'unit_price_usd' => 8, 'cost_usd' => 5, 'line_total_usd' => 28, 'tax_rate' => 0, 'tax_amount_usd' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        (require database_path('migrations/2026_11_11_000001_packaging_add_columns.php'))->up();

        $row = \Illuminate\Support\Facades\DB::table('sale_items')->first();
        $this->assertEquals(3.5, $row->base_qty);
        $this->assertSame('pack', $row->sale_unit);
        $this->assertEquals(1, $row->unit_multiplier);
    }
}
