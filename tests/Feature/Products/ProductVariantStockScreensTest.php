<?php

namespace Tests\Feature\Products;

use App\Models\AuditLog;
use App\Models\InventoryAdjustment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Everything around a variant that is not selling it: adjusting its stock, the receipts and screens that print
 * its name, the movement log and the stock levels report.
 */
class ProductVariantStockScreensTest extends TestCase
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

        // Main item 10, Red 6, Blue 4.
        $this->product = Product::factory()->create(['name' => 'Soap', 'name_ar' => 'صابون', 'barcode' => '7770000000001', 'price_usd' => 10, 'stock_qty' => 10, 'is_taxable' => false]);
        $this->red = ProductVariantsTest::variantWithStock($this->product, '7770000000002', 6, ['label' => 'Red', 'label_ar' => 'أحمر']);
        $this->blue = ProductVariantsTest::variantWithStock($this->product, '7770000000003', 4, ['label' => 'Blue']);
    }

    private function stockUser(): User
    {
        return User::factory()->stock()->create();
    }

    private function adjust(array $body)
    {
        return $this->actingAs($this->stockUser())->post(route('products.adjust-stock', $this->product), $body + ['reason' => 'Shelf count']);
    }

    private function counts(): array
    {
        return [
            'total' => (float) $this->product->fresh()->stock_qty,
            'main' => $this->product->fresh()->mainStockQty(),
            'red' => (float) $this->red->fresh()->stock_qty,
            'blue' => (float) $this->blue->fresh()->stock_qty,
        ];
    }

    private function sellBilingual(): Sale
    {
        $cashier = User::factory()->create(['role' => User::ROLE_CASHIER]);
        Shift::create(['user_id' => $cashier->id, 'opened_at' => now(), 'opening_cash_usd' => 0, 'opening_cash_lbp' => 0, 'status' => Shift::STATUS_OPEN]);
        $this->actingAs($cashier)->postJson('/pos/api/sales', [
            'cart' => [
                ['product_id' => $this->product->id, 'product_variant_id' => $this->red->id, 'qty' => 2],
                ['product_id' => $this->product->id, 'product_variant_id' => $this->blue->id, 'qty' => 1],
                ['product_id' => $this->product->id, 'qty' => 1],
            ],
            'payment' => ['amount_usd' => 100],
        ])->assertCreated();

        return Sale::latest('id')->first();
    }

    // --- adjusting stock by item ----------------------------------------------------------------

    public function test_a_variants_stock_is_adjusted_by_add_remove_and_set_and_the_total_follows(): void
    {
        $this->adjust(['action' => 'add', 'qty' => 3, 'variant_id' => $this->red->id])->assertSessionHasNoErrors();
        $this->assertSame(['total' => 23.0, 'main' => 10.0, 'red' => 9.0, 'blue' => 4.0], $this->counts());

        $this->adjust(['action' => 'remove', 'qty' => 1, 'variant_id' => $this->blue->id])->assertSessionHasNoErrors();
        $this->assertSame(['total' => 22.0, 'main' => 10.0, 'red' => 9.0, 'blue' => 3.0], $this->counts());

        $this->adjust(['action' => 'set', 'qty' => 2, 'variant_id' => $this->red->id])->assertSessionHasNoErrors();
        $this->assertSame(['total' => 15.0, 'main' => 10.0, 'red' => 2.0, 'blue' => 3.0], $this->counts());

        $log = InventoryAdjustment::where('type', InventoryAdjustment::TYPE_SET)->sole();
        $this->assertSame($this->red->id, $log->product_variant_id);
        $this->assertEquals(-7, (float) $log->qty_change);
    }

    public function test_an_adjustment_without_an_item_is_for_the_main_item(): void
    {
        $this->adjust(['action' => 'add', 'qty' => 5])->assertSessionHasNoErrors();
        $this->assertSame(['total' => 25.0, 'main' => 15.0, 'red' => 6.0, 'blue' => 4.0], $this->counts());

        $this->adjust(['action' => 'set', 'qty' => 1])->assertSessionHasNoErrors();
        $this->assertSame(['total' => 11.0, 'main' => 1.0, 'red' => 6.0, 'blue' => 4.0], $this->counts());
        $this->assertNull(InventoryAdjustment::where('type', InventoryAdjustment::TYPE_SET)->sole()->product_variant_id);
    }

    public function test_removing_more_than_an_item_holds_is_refused_even_when_the_product_has_stock(): void
    {
        $this->adjust(['action' => 'remove', 'qty' => 5, 'variant_id' => $this->blue->id])->assertSessionHasErrors('stock');
        $this->adjust(['action' => 'remove', 'qty' => 11])->assertSessionHasErrors('stock'); // the main item has 10

        $this->assertSame(['total' => 20.0, 'main' => 10.0, 'red' => 6.0, 'blue' => 4.0], $this->counts());
    }

    public function test_a_variant_of_another_product_cannot_be_adjusted_here(): void
    {
        $other = ProductVariantsTest::variantWithStock(Product::factory()->create(), '7770000000050', 3);

        $this->adjust(['action' => 'add', 'qty' => 1, 'variant_id' => $other->id])->assertSessionHasErrors('variant_id');
        $this->assertEquals(3, (float) $other->fresh()->stock_qty);
    }

    public function test_the_audit_entry_names_the_variant_and_its_own_quantity(): void
    {
        $this->adjust(['action' => 'add', 'qty' => 2, 'variant_id' => $this->red->id]);

        $log = AuditLog::where('action', 'stock_adjust')->sole();
        $this->assertSame($this->red->id, $log->new_values['variant_id']);
        $this->assertEquals(6, $log->old_values['variant_qty']);
        $this->assertEquals(8, $log->new_values['variant_qty']);
        $this->assertEquals(20, $log->old_values['stock_qty']); // the product total, as ever
        $this->assertEquals(22, $log->new_values['stock_qty']);
    }

    public function test_the_adjustment_form_offers_each_item_and_scans_a_barcode_to_pick_one(): void
    {
        $this->actingAs($this->stockUser())->get(route('products.edit', $this->product))->assertOk()
            ->assertSee('name="variant_id"', false)
            ->assertSee('Main item (10)')->assertSee('Soap — Red (6)')->assertSee('Soap — Blue (4)')
            ->assertSee('7770000000002', false);

        $plain = Product::factory()->create();
        $this->actingAs($this->stockUser())->get(route('products.edit', $plain))->assertOk()->assertDontSee('name="variant_id"', false);
    }

    public function test_a_variants_own_stock_can_be_taken_to_zero_and_then_deleted(): void
    {
        $this->adjust(['action' => 'set', 'qty' => 0, 'variant_id' => $this->blue->id])->assertSessionHasNoErrors();

        $this->actingAs(User::factory()->admin()->create())->put(route('products.update', $this->product), [
            'name' => 'Soap', 'category_id' => $this->product->category_id, 'price_usd' => 10,
            'variants' => [['id' => $this->blue->id, 'remove' => 1]],
        ])->assertSessionHasNoErrors();

        $this->assertNotNull(ProductVariant::withTrashed()->find($this->blue->id)->deleted_at);
        $this->assertSame(['total' => 16.0, 'main' => 10.0, 'red' => 6.0], array_diff_key($this->counts(), ['blue' => 1]));
    }

    // --- names on receipts and screens ---------------------------------------------------------

    public function test_a_bilingual_receipt_prints_each_variants_english_and_arabic_name(): void
    {
        Setting::set('receipt_language', 'bilingual', 'receipt');
        $sale = $this->sellBilingual();

        $this->actingAs(User::factory()->admin()->create())->get(route('receipts.print', ['sale' => $sale, 'format' => 'thermal', 'auto' => 0]))
            ->assertOk()
            ->assertSeeInOrder(['Soap — Red', 'صابون — أحمر', 'Soap — Blue', 'صابون — Blue'], false)
            ->assertSee('Soap', false);
    }

    public function test_an_arabic_receipt_uses_the_arabic_names_and_falls_back_to_the_label_without_one(): void
    {
        Setting::set('receipt_language', 'ar', 'receipt');
        $sale = $this->sellBilingual();

        $this->actingAs(User::factory()->admin()->create())->get(route('receipts.print', ['sale' => $sale, 'format' => 'thermal', 'auto' => 0]))
            ->assertOk()->assertSee('صابون — أحمر', false)->assertSee('صابون — Blue', false);
    }

    public function test_the_receipt_and_sale_page_keep_reading_the_snapshot_when_the_label_changes_or_the_variant_is_deleted(): void
    {
        $sale = $this->sellBilingual();
        $this->red->update(['label' => 'Crimson']);
        $this->blue->update(['stock_qty' => 0]);
        $this->blue->update(['barcode' => null, 'old_barcode' => '7770000000003']);
        $this->blue->delete();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('sales.show', $sale))->assertOk()
            ->assertSee('Soap — Red')->assertSee('Soap — Blue')->assertDontSee('Crimson');
        $this->actingAs($admin)->get(route('receipts.print', ['sale' => $sale, 'format' => 'thermal', 'auto' => 0]))
            ->assertOk()->assertSee('Soap — Red')->assertDontSee('Crimson');
        $this->assertSame($this->blue->id, $sale->items->firstWhere('product_name', 'Soap — Blue')->product_variant_id);
    }

    public function test_the_a4_invoice_lines_carry_the_variant_name_in_both_languages(): void
    {
        $sale = $this->sellBilingual();

        $lines = app(\App\Services\ReceiptPrintService::class)->invoice($sale->load('items.product', 'items.variant'))['lines'];

        $this->assertSame(['Soap — Red', 'Soap — Blue', 'Soap'], array_column($lines, 'name'));
        $this->assertSame(['صابون — أحمر', 'صابون — Blue', 'صابون'], array_column($lines, 'name_ar'));
    }

    public function test_the_return_screens_name_the_variant(): void
    {
        $sale = $this->sellBilingual();
        $manager = User::factory()->manager()->create();
        Shift::create(['user_id' => $manager->id, 'opened_at' => now(), 'opening_cash_usd' => 0, 'opening_cash_lbp' => 0, 'status' => Shift::STATUS_OPEN]);

        $this->actingAs($manager)->get(route('returns.create', $sale))->assertOk()->assertSee('Soap — Red')->assertSee('Soap — Blue');

        $line = $sale->items->firstWhere('product_variant_id', $this->red->id);
        $this->actingAs($manager)->post(route('returns.store', $sale), [
            'items' => [['sale_item_id' => $line->id, 'qty_returned' => 1, 'restock' => 1]],
            'reason' => 'other', 'refund_method' => 'cash_usd',
        ])->assertSessionHasNoErrors();

        $this->actingAs($manager)->get(route('returns.show', \App\Models\ReturnTransaction::sole()))->assertOk()->assertSee('Soap — Red');
    }

    // --- movements and stock levels ------------------------------------------------------------------

    public function test_the_movement_log_shows_the_variant_and_filters_by_item(): void
    {
        $this->adjust(['action' => 'add', 'qty' => 2, 'variant_id' => $this->red->id]);
        $this->adjust(['action' => 'add', 'qty' => 5]);
        $admin = User::factory()->admin()->create();
        $query = ['from' => '2000-01-01', 'to' => now()->addDay()->format('Y-m-d')];

        $all = $this->actingAs($admin)->get('/products/movements?'.http_build_query($query))->assertOk();
        $all->assertSee('Variant')->assertSee('Red');

        $variantsOnly = $this->actingAs($admin)->get('/products/movements?'.http_build_query($query + ['item' => 'variants']))->assertOk();
        $variantsOnly->assertSee('Red')->assertSee('+2')->assertDontSee('+5');

        $mainOnly = $this->actingAs($admin)->get('/products/movements?'.http_build_query($query + ['item' => 'main']))->assertOk();
        $mainOnly->assertSee('+5')->assertDontSee('+2');

        $rows = app(\App\Services\StockMovementService::class)->exportRows(app(\App\Services\StockMovementService::class)->filters($query));
        $last = fn (array $row) => $row[array_key_last($row)];
        $this->assertSame('Variant', $last($rows[0])); // the last column: the others keep their place
        $this->assertContains('Red', array_map($last, $rows));
    }

    public function test_the_stock_levels_report_lists_the_main_item_and_variants_under_their_product(): void
    {
        $plain = Product::factory()->create(['name' => 'Plain', 'stock_qty' => 3]);

        $report = app(\App\Services\ReportService::class)->stockLevels();
        $row = collect($report['rows'])->firstWhere('id', $this->product->id);

        $this->assertEquals(20, $row['stock_qty']);                              // the product row is the total
        $this->assertSame([10.0, 6.0, 4.0], array_column($row['breakdown'], 'qty'));
        $this->assertSame([null, 'Red', 'Blue'], array_column($row['breakdown'], 'name'));
        $this->assertSame([], collect($report['rows'])->firstWhere('id', $plain->id)['breakdown']);

        // Totals count each product once, at the product's own stock.
        $this->assertEqualsWithDelta((float) $this->product->cost_usd * 20 + (float) $plain->cost_usd * 3, $report['totals']['value_cost'], 0.05);

        $this->actingAs(User::factory()->admin()->create())->get(route('reports.stock-levels'))->assertOk()
            ->assertSee('Main item')->assertSee('Red')->assertSee('Blue');
    }
}
