<?php

namespace Tests\Feature\Products;

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\InventoryAdjustment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\Supplier;
use App\Models\User;
use App\Services\BarcodeService;
use App\Services\ProductImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Packaging on the product side (client/tobacco, docs/packaging.md): the form fields and their validation, the carton
 * barcode as an occupied barcode everywhere a barcode is checked, and receiving stock by the package or the carton.
 */
class PackagingProductTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['pos.features.packaging' => true]);
    }

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    /** A valid form post for a product sold as packs, packages of 10 and a carton of 20 packages. */
    private function payload(array $over = []): array
    {
        return $over + [
            'name' => 'Marlboro Red', 'category_id' => Category::factory()->create()->id, 'price_usd' => 8,
            'barcode' => '5000000000017',
            'has_package' => 1, 'package_size' => 10, 'package_price_usd' => 75, 'package_wholesale_price_usd' => 70, 'package_vip_price_usd' => '',
            'carton_barcode' => '5000000000024', 'packages_per_carton' => 20, 'sell_carton_at_pos' => 0,
            'carton_price_usd' => '', 'carton_wholesale_price_usd' => '', 'carton_vip_price_usd' => '',
        ];
    }

    private function tobacco(array $over = []): Product
    {
        return Product::factory()->create($over + [
            'barcode' => '5000000000017', 'stock_qty' => 0, 'has_package' => true, 'package_size' => 10, 'package_price_usd' => 75,
            'carton_barcode' => '5000000000024', 'packages_per_carton' => 20,
        ]);
    }

    // --- the form ------------------------------------------------------------------------

    public function test_the_form_saves_the_packaging_fields(): void
    {
        $this->actingAs($this->admin())->post('/products', $this->payload(['sell_carton_at_pos' => 1, 'carton_price_usd' => 1400]))
            ->assertSessionHasNoErrors();

        $p = Product::sole();
        $this->assertTrue($p->has_package);
        $this->assertSame(10, $p->package_size);
        $this->assertEquals(75, $p->package_price_usd);
        $this->assertEquals(70, $p->package_wholesale_price_usd);
        $this->assertNull($p->package_vip_price_usd);
        $this->assertSame('5000000000024', $p->carton_barcode);
        $this->assertSame(20, $p->packages_per_carton);
        $this->assertTrue($p->sell_carton_at_pos);
        $this->assertSame(200, $p->cartonPacks());
        $this->assertEquals(200.0, $p->multiplierFor('carton'));
    }

    public function test_a_plain_product_still_saves_with_no_packaging_fields_at_all(): void
    {
        $this->actingAs($this->admin())->post('/products', [
            'name' => 'Gum', 'category_id' => Category::factory()->create()->id, 'price_usd' => 1,
        ])->assertSessionHasNoErrors();

        $p = Product::sole();
        $this->assertFalse($p->has_package);
        $this->assertNull($p->carton_barcode);
        $this->assertSame(['pack'], $p->receivableUnits());
    }

    public function test_with_the_flag_off_posted_packaging_fields_are_ignored(): void
    {
        config(['pos.features.packaging' => false]);

        $this->actingAs($this->admin())->post('/products', $this->payload())->assertSessionHasNoErrors();

        $p = Product::sole();
        $this->assertFalse($p->has_package);
        $this->assertNull($p->package_size);
        $this->assertNull($p->carton_barcode);
    }

    public function test_unticking_the_package_clears_what_was_hidden_behind_it(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post('/products', $this->payload())->assertSessionHasNoErrors();
        $p = Product::sole();

        $this->actingAs($admin)->put(route('products.update', $p), $this->payload([
            'has_package' => 0, 'carton_barcode' => '', 'category_id' => $p->category_id,
        ]))->assertSessionHasNoErrors();

        $p = $p->fresh();
        $this->assertFalse($p->has_package);
        $this->assertNull($p->package_size);
        $this->assertNull($p->package_price_usd);
        $this->assertNull($p->carton_barcode);
        $this->assertNull($p->packages_per_carton);
    }

    public function test_the_package_needs_a_size_of_at_least_two_and_a_retail_price(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/products', $this->payload(['package_size' => 1, 'carton_barcode' => '']))
            ->assertSessionHasErrors('package_size');
        $this->actingAs($admin)->post('/products', $this->payload(['package_price_usd' => '', 'carton_barcode' => '']))
            ->assertSessionHasErrors('package_price_usd');
        $this->assertSame(0, Product::count());
    }

    public function test_a_carton_needs_the_package_a_pack_count_and_a_price_when_sold_at_the_till(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/products', $this->payload(['has_package' => 0]))
            ->assertSessionHasErrors('carton_barcode');
        $this->actingAs($admin)->post('/products', $this->payload(['packages_per_carton' => '']))
            ->assertSessionHasErrors('packages_per_carton');
        $this->actingAs($admin)->post('/products', $this->payload(['sell_carton_at_pos' => 1, 'carton_price_usd' => '']))
            ->assertSessionHasErrors('carton_price_usd');
        $this->assertSame(0, Product::count());
    }

    public function test_packaging_is_for_simple_products_only(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/products', $this->payload(['type' => 'bundle']))->assertSessionHasErrors('has_package');
        $this->assertSame(0, Product::count());

        $simple = Product::factory()->create(['barcode' => 'S-1']);
        ProductVariant::create(['product_id' => $simple->id, 'attributes' => ['size' => 'L'], 'barcode' => 'V-1']);
        $this->actingAs($admin)->put(route('products.update', $simple), $this->payload(['barcode' => 'S-1', 'carton_barcode' => '5000000000099', 'category_id' => $simple->category_id]))
            ->assertSessionHasErrors('has_package');
    }

    public function test_the_form_renders_with_the_packaging_section_only_on_a_packaging_build(): void
    {
        $admin = $this->admin();
        $p = $this->tobacco(['stock_qty' => 5]);

        $this->actingAs($admin)->get('/products/create')->assertOk()->assertSee('packagingSection(', false);
        $edit = $this->actingAs($admin)->get(route('products.edit', $p))->assertOk();
        $edit->assertSee('packagingSection(', false)->assertSee('stockReceive(', false)->assertSee('name="carton_barcode"', false);
        $edit->assertSee(__('packaging.form.section'))->assertSee(__('packaging.receive.scan_carton'));

        config(['pos.features.packaging' => false]);
        $this->actingAs($admin)->get('/products/create')->assertOk()->assertDontSee('packagingSection(', false);
        $this->actingAs($admin)->get(route('products.edit', $p))->assertOk()
            ->assertDontSee('stockReceive(', false)->assertDontSee('name="carton_barcode"', false);
    }

    // --- barcodes ------------------------------------------------------------------------

    public function test_a_carton_barcode_cannot_be_another_products_pack_barcode(): void
    {
        Product::factory()->create(['barcode' => '111']);

        $this->actingAs($this->admin())->post('/products', $this->payload(['barcode' => '999', 'carton_barcode' => '111']))
            ->assertSessionHasErrors('carton_barcode');
    }

    public function test_a_carton_barcode_cannot_be_another_products_carton_barcode(): void
    {
        $this->tobacco(['barcode' => 'A-1', 'carton_barcode' => 'CTN-1']);

        $this->actingAs($this->admin())->post('/products', $this->payload(['barcode' => 'B-1', 'carton_barcode' => 'CTN-1']))
            ->assertSessionHasErrors('carton_barcode');
    }

    public function test_a_carton_barcode_cannot_be_a_variant_barcode(): void
    {
        $other = Product::factory()->create();
        ProductVariant::create(['product_id' => $other->id, 'attributes' => ['size' => 'L'], 'barcode' => 'VAR-1']);

        $this->actingAs($this->admin())->post('/products', $this->payload(['carton_barcode' => 'VAR-1']))
            ->assertSessionHasErrors('carton_barcode');
    }

    public function test_a_pack_barcode_cannot_be_any_products_carton_barcode_either(): void
    {
        $this->tobacco(['barcode' => 'A-1', 'carton_barcode' => 'CTN-1']);

        $this->actingAs($this->admin())->post('/products', $this->payload(['barcode' => 'CTN-1', 'carton_barcode' => 'CTN-2']))
            ->assertSessionHasErrors('barcode');
    }

    public function test_a_products_carton_barcode_cannot_equal_its_own_pack_barcode(): void
    {
        $this->actingAs($this->admin())->post('/products', $this->payload(['barcode' => 'SAME', 'carton_barcode' => 'SAME']))
            ->assertSessionHasErrors();
        $this->assertSame(0, Product::count());
    }

    public function test_a_product_can_be_saved_again_with_its_own_codes(): void
    {
        $admin = $this->admin();
        $p = $this->tobacco();

        $this->actingAs($admin)->put(route('products.update', $p), $this->payload(['category_id' => $p->category_id]))->assertSessionHasNoErrors();
    }

    public function test_find_by_barcode_says_which_kind_of_code_matched(): void
    {
        $p = $this->tobacco(['barcode' => 'PACK-1', 'carton_barcode' => 'CTN-1']);
        $barcodes = app(BarcodeService::class);

        $this->assertSame('pack', $barcodes->findByBarcode('PACK-1')->barcode_kind);
        $this->assertSame('carton', $barcodes->findByBarcode('CTN-1')->barcode_kind);
        $this->assertSame($p->id, $barcodes->findByBarcode('CTN-1')->id);
        $this->assertNull($barcodes->findByBarcode('CTN-1', $p->id), 'a product does not clash with itself');
        $this->assertNull($barcodes->findByBarcode('NOPE'));

        $v = ProductVariant::create(['product_id' => $p->id, 'attributes' => ['x' => 1], 'barcode' => 'VAR-1']);
        $this->assertSame('variant', $barcodes->findByBarcode('VAR-1')->barcode_kind);
    }

    public function test_the_live_check_and_the_till_lookup_report_a_carton(): void
    {
        $admin = $this->admin();
        $p = $this->tobacco(['barcode' => 'PACK-1', 'carton_barcode' => 'CTN-1']);
        Shift::create(['user_id' => $admin->id, 'opened_at' => now(), 'opening_cash_usd' => 0, 'opening_cash_lbp' => 0, 'status' => Shift::STATUS_OPEN]);

        $this->actingAs($admin)->getJson(route('products.check-barcode', ['barcode' => 'CTN-1']))
            ->assertOk()->assertJson(['exists' => true, 'kind' => 'carton', 'product_id' => $p->id]);
        $this->actingAs($admin)->getJson(route('products.check-barcode', ['barcode' => 'PACK-1']))
            ->assertOk()->assertJson(['exists' => true, 'kind' => 'pack']);

        $this->actingAs($admin)->getJson(route('pos.api.barcode', ['code' => 'CTN-1']))
            ->assertOk()->assertJson(['id' => $p->id, 'barcode_kind' => 'carton']);
        $this->actingAs($admin)->getJson(route('pos.api.barcode', ['code' => 'PACK-1']))
            ->assertOk()->assertJson(['id' => $p->id, 'barcode_kind' => 'pack']);

        config(['pos.features.packaging' => false]);
        $this->actingAs($admin)->getJson(route('pos.api.barcode', ['code' => 'CTN-1']))->assertNotFound();
    }

    public function test_the_import_reports_a_carton_barcode_as_a_duplicate(): void
    {
        Setting::set('bulk_upload_enabled_admin', '1', 'permissions', 'bool');
        Category::factory()->create(['name' => 'Beverages']);
        $this->tobacco(['carton_barcode' => 'CTN-1']);

        $path = tempnam(sys_get_temp_dir(), 'import').'.csv';
        file_put_contents($path, "name,category,price_usd,barcode,sku\nGum,Beverages,1,CTN-1,G1\nMint,Beverages,1,MINT-1,M1\n");
        $report = app(ProductImportService::class)->analyse($path);

        $this->assertSame([], $report['errors']);
        $this->assertCount(1, $report['duplicates']);
        $this->assertSame('CTN-1', $report['duplicates'][0]['barcode']);
        $this->assertCount(1, $report['new']);
    }

    public function test_deleting_a_product_frees_its_carton_barcode_and_the_audit_row_keeps_it(): void
    {
        $admin = $this->admin();
        $p = $this->tobacco(['carton_barcode' => 'CTN-1']);

        $this->actingAs($admin)->delete(route('products.destroy', $p))->assertSessionHasNoErrors();

        $this->assertNull(Product::withTrashed()->find($p->id)->carton_barcode);
        $this->assertSame('CTN-1', AuditLog::where('action', 'delete')->sole()->old_values['carton_barcode']);

        $this->actingAs($admin)->post('/products', $this->payload(['barcode' => 'NEW-1', 'carton_barcode' => 'CTN-1']))->assertSessionHasNoErrors();
        $this->assertSame(1, Product::where('carton_barcode', 'CTN-1')->count());
    }

    // --- receiving ------------------------------------------------------------------------

    public function test_receiving_three_cartons_adds_the_packs_and_logs_one_movement(): void
    {
        $p = $this->tobacco();

        $this->actingAs(User::factory()->stock()->create())->post(route('products.adjust-stock', $p), [
            'action' => 'add', 'qty' => 3, 'unit' => 'carton', 'reason' => 'Delivery',
        ])->assertSessionHasNoErrors();

        $this->assertEquals(600, $p->fresh()->stock_qty, '3 x 20 x 10 packs');
        $move = InventoryAdjustment::where('product_id', $p->id)->sole();
        $this->assertEquals(600, $move->qty_change);
        $this->assertStringContainsString('Delivery', $move->reason);
        $this->assertStringContainsString('3 × Carton = 600 packs', $move->reason);
        $this->assertEquals(600, AuditLog::where('action', 'stock_adjust')->sole()->new_values['qty']);
    }

    public function test_receiving_by_package_and_by_pack_and_a_unit_the_product_lacks(): void
    {
        $p = $this->tobacco();
        $stock = User::factory()->stock()->create();

        $this->actingAs($stock)->post(route('products.adjust-stock', $p), ['action' => 'add', 'qty' => 2, 'unit' => 'package', 'reason' => 'x'])->assertSessionHasNoErrors();
        $this->actingAs($stock)->post(route('products.adjust-stock', $p), ['action' => 'add', 'qty' => 5, 'reason' => 'x'])->assertSessionHasNoErrors();
        $this->assertEquals(25, $p->fresh()->stock_qty);

        $plain = Product::factory()->create(['stock_qty' => 0]);
        $this->actingAs($stock)->post(route('products.adjust-stock', $plain), ['action' => 'add', 'qty' => 2, 'unit' => 'carton', 'reason' => 'x'])
            ->assertSessionHasErrors('unit');
        $this->assertEquals(0, $plain->fresh()->stock_qty);

        config(['pos.features.packaging' => false]);
        $this->actingAs($stock)->post(route('products.adjust-stock', $p), ['action' => 'add', 'qty' => 2, 'unit' => 'package', 'reason' => 'x'])
            ->assertSessionHasErrors('unit');
        $this->assertEquals(25, $p->fresh()->stock_qty);
    }

    public function test_a_purchase_order_can_be_received_by_the_carton_with_the_cost_per_carton(): void
    {
        $manager = User::factory()->manager()->create();
        $p = $this->tobacco(['cost_usd' => 5]);
        $supplier = Supplier::create(['name' => 'Acme', 'payment_terms' => 'NET30', 'is_active' => true]);
        $po = PurchaseOrder::create([
            'po_number' => 'PO-1', 'supplier_id' => $supplier->id, 'user_id' => $manager->id, 'status' => 'sent',
            'subtotal_usd' => 0, 'tax_usd' => 0, 'shipping_usd' => 0, 'total_usd' => 0,
        ]);
        $item = PurchaseOrderItem::create([
            'purchase_order_id' => $po->id, 'product_id' => $p->id, 'product_name' => $p->name,
            'qty_ordered' => 1000, 'qty_received' => 0, 'cost_usd' => 5, 'tax_rate' => 0, 'line_total_usd' => 5000,
        ]);

        $this->actingAs($manager)->post(route('purchases.receive', $po), [
            'receipts' => [['item_id' => $item->id, 'qty' => 2, 'unit' => 'carton', 'cost_usd' => 900]],
        ])->assertSessionHasNoErrors();

        $this->assertEquals(400, $p->fresh()->stock_qty);
        $this->assertEquals(400, $item->fresh()->qty_received);
        $this->assertEquals(4.5, (float) $p->fresh()->cost_usd, '$900 a carton of 200 packs = $4.50 a pack');
        $this->assertStringContainsString('2 × Carton = 400 packs', InventoryAdjustment::where('product_id', $p->id)->sole()->notes);
        $this->assertEquals(1800, (float) $supplier->fresh()->balance, 'we owe 400 packs x $4.50');

        // More than is left on the order (600 packs) is refused, in packs.
        $this->actingAs($manager)->post(route('purchases.receive', $po), [
            'receipts' => [['item_id' => $item->id, 'qty' => 4, 'unit' => 'carton']],
        ]);
        $this->assertEquals(400, $p->fresh()->stock_qty);
    }

    public function test_the_purchase_order_page_offers_the_units_only_for_a_packaged_product(): void
    {
        $manager = User::factory()->manager()->create();
        $packaged = $this->tobacco();
        $plain = Product::factory()->create();
        $supplier = Supplier::create(['name' => 'Acme', 'payment_terms' => 'NET30', 'is_active' => true]);
        $po = PurchaseOrder::create([
            'po_number' => 'PO-1', 'supplier_id' => $supplier->id, 'user_id' => $manager->id, 'status' => 'sent',
            'subtotal_usd' => 0, 'tax_usd' => 0, 'shipping_usd' => 0, 'total_usd' => 0,
        ]);
        foreach ([$packaged, $plain] as $prod) {
            PurchaseOrderItem::create([
                'purchase_order_id' => $po->id, 'product_id' => $prod->id, 'product_name' => $prod->name,
                'qty_ordered' => 10, 'qty_received' => 0, 'cost_usd' => 5, 'tax_rate' => 0, 'line_total_usd' => 50,
            ]);
        }

        $html = $this->actingAs($manager)->get(route('purchases.show', $po))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, '][unit]"'), 'one unit selector: the packaged product only');
        $this->assertSame(3, substr_count($html, '<option value="'), '1 option each for pack, package, carton');
    }
}
