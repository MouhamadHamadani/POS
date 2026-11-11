<?php

namespace Tests\Feature\Products;

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\InventoryAdjustment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\BarcodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Variants: another barcode under a product, at the product's price, with its own label and its own stock.
 * products.stock_qty stays the TOTAL (main item + every variant); the main item's count is derived.
 */
class ProductVariantsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    private function create(array $extra = [], array $variants = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin())->post('/products', array_merge([
            'name' => 'Soap',
            'category_id' => Category::factory()->create()->id,
            'price_usd' => 2,
            'barcode' => '1111111111111',
            'stock_qty' => 5,
            'variants' => $variants,
        ], $extra));
    }

    private function threeVariants(): array
    {
        return [
            ['barcode' => '2000000000001', 'label' => 'Red', 'stock_qty' => 3],
            ['barcode' => '2000000000002', 'label' => 'Blue', 'stock_qty' => 4],
            ['barcode' => '2000000000003', 'label' => 'Green'],
            ['barcode' => '', 'label' => ''], // the empty trailing row a scanner-driven form leaves
        ];
    }

    /** A variant holding stock, with the product total kept in step the way InventoryService does. */
    public static function variantWithStock(Product $product, string $barcode, float $stock, array $extra = []): ProductVariant
    {
        $variant = ProductVariant::factory()->create(['product_id' => $product->id, 'barcode' => $barcode] + $extra);
        if ($stock != 0.0) {
            app(\App\Services\InventoryService::class)->recordOpening($product, $stock, User::factory()->admin()->create()->id, $variant);
        }

        return $variant->fresh();
    }

    // --- create -------------------------------------------------------------------------------

    public function test_a_product_is_created_with_its_variants_in_one_request(): void
    {
        $this->create(variants: $this->threeVariants())->assertSessionHasNoErrors();

        $product = Product::sole();
        $this->assertSame(['2000000000001', '2000000000002', '2000000000003'], $product->variants()->orderBy('id')->pluck('barcode')->all());
        $this->assertSame(['Red', 'Blue', 'Green'], $product->variants()->orderBy('id')->pluck('label')->all());
    }

    public function test_opening_stock_is_logged_per_variant_and_the_product_total_is_main_plus_variants(): void
    {
        $this->create(variants: $this->threeVariants())->assertSessionHasNoErrors();

        $product = Product::sole()->load('variants');
        $this->assertEquals(12, $product->stock_qty);        // 5 main + 3 + 4
        $this->assertEquals(5, $product->mainStockQty());    // derived, never stored
        $this->assertEquals([3, 4, 0], $product->variants->sortBy('id')->map(fn ($v) => (float) $v->stock_qty)->values()->all());

        $openings = InventoryAdjustment::where('type', InventoryAdjustment::TYPE_OPENING)->orderBy('id')->get();
        $this->assertCount(3, $openings); // main, Red, Blue (Green is 0)
        $this->assertNull($openings[0]->product_variant_id);
        $this->assertSame($product->variants->firstWhere('label', 'Red')->id, $openings[1]->product_variant_id);
        $this->assertEquals(12, (float) DB::table('inventory_adjustments')->where('product_id', $product->id)->sum('qty_change'));
    }

    public function test_a_variant_without_a_barcode_gets_one_generated(): void
    {
        $this->create(variants: [['barcode' => '', 'label' => 'Plain']])->assertSessionHasNoErrors();

        $this->assertMatchesRegularExpression('/^\d{13}$/', ProductVariant::sole()->barcode);
    }

    public function test_the_audit_log_records_each_variant_created(): void
    {
        $this->create(variants: $this->threeVariants())->assertSessionHasNoErrors();

        $this->assertSame(3, AuditLog::where('action', 'variant_create')->where('model_type', ProductVariant::class)->count());
    }

    // --- one barcode namespace ----------------------------------------------------------------

    public function test_a_variant_barcode_cannot_be_another_products_barcode(): void
    {
        Product::factory()->create(['barcode' => '3000000000001']);

        $this->create(variants: [['barcode' => '3000000000001', 'label' => 'Red']])
            ->assertSessionHasErrors('variants.0.barcode');
        $this->assertSame(0, ProductVariant::count());
    }

    public function test_a_variant_barcode_cannot_be_another_variants_barcode(): void
    {
        $other = Product::factory()->create();
        self::variantWithStock($other, '3000000000002', 0);

        $this->create(variants: [['barcode' => '3000000000002', 'label' => 'Red']])
            ->assertSessionHasErrors('variants.0.barcode');
    }

    public function test_a_variant_barcode_cannot_equal_its_own_products_barcode(): void
    {
        $this->create(variants: [['barcode' => '1111111111111', 'label' => 'Red']])
            ->assertSessionHasErrors('variants.0.barcode');
    }

    public function test_the_same_barcode_twice_in_one_form_is_rejected(): void
    {
        $this->create(variants: [
            ['barcode' => '3000000000003', 'label' => 'Red'],
            ['barcode' => '3000000000003', 'label' => 'Blue'],
        ])->assertSessionHasErrors('variants.1.barcode');
        $this->assertSame(0, Product::count());
    }

    public function test_a_product_barcode_cannot_be_a_variants_barcode(): void
    {
        $owner = Product::factory()->create();
        self::variantWithStock($owner, '3000000000004', 0);

        $this->create(['barcode' => '3000000000004'])->assertSessionHasErrors('barcode');
    }

    public function test_the_barcode_check_endpoint_sees_variants_and_names_them(): void
    {
        $product = Product::factory()->create(['name' => 'Candle']);
        $variant = self::variantWithStock($product, '3000000000005', 0, ['label' => 'Vanilla']);
        $admin = $this->admin();

        $this->actingAs($admin)->getJson('/products/check-barcode?barcode=3000000000005')
            ->assertOk()->assertJson(['exists' => true, 'kind' => 'variant', 'product_name' => 'Candle — Vanilla']);
        // Editing that very variant: its own code is not "taken".
        $this->actingAs($admin)->getJson('/products/check-barcode?barcode=3000000000005&except_variant='.$variant->id)
            ->assertOk()->assertJson(['exists' => false]);
    }

    public function test_find_by_barcode_describes_a_product_a_variant_or_nothing(): void
    {
        $product = Product::factory()->create(['barcode' => '4000000000001']);
        $variant = self::variantWithStock($product, '4000000000002', 0);
        $service = app(BarcodeService::class);

        $hit = $service->findByBarcode('4000000000001');
        $this->assertSame('product', $hit['kind']);
        $this->assertSame($product->id, $hit['product']->id);
        $this->assertNull($hit['variant']);

        $hit = $service->findByBarcode(' 4000000000002 ');
        $this->assertSame('variant', $hit['kind']);
        $this->assertSame($variant->id, $hit['variant']->id);
        $this->assertSame($product->id, $hit['product']->id);

        $this->assertNull($service->findByBarcode('4000000000009'));
        $this->assertNull($service->findByBarcode('4000000000002', exceptVariantId: $variant->id));
    }

    public function test_the_bulk_import_reports_a_variant_barcode_as_a_duplicate(): void
    {
        $product = Product::factory()->create(['name' => 'Candle']);
        self::variantWithStock($product, '4000000000003', 0, ['label' => 'Vanilla']);
        Category::factory()->create(['name' => 'Home']);
        $path = tempnam(sys_get_temp_dir(), 'import') . '.csv';
        file_put_contents($path, "name,category,price_usd,barcode
Water,Home,1,4000000000003");

        $report = app(\App\Services\ProductImportService::class)->analyse($path);

        $this->assertSame([], $report['errors']);
        $this->assertSame([], $report['new']);
        $this->assertSame('Candle — Vanilla', $report['duplicates'][0]['existing']);
    }

    // --- release on delete --------------------------------------------------------------------

    public function test_deleting_a_product_releases_its_variants_barcodes_and_remembers_them(): void
    {
        $this->create(variants: [['barcode' => '5000000000001', 'label' => 'Red', 'sku' => 'RED-1']])->assertSessionHasNoErrors();
        $product = Product::sole();

        $this->actingAs($this->admin())->delete(route('products.destroy', $product))->assertRedirect();

        $variant = ProductVariant::withTrashed()->sole();
        $this->assertNotNull($variant->deleted_at);
        $this->assertNull($variant->barcode);
        $this->assertNull($variant->sku);
        $this->assertSame('5000000000001', $variant->old_barcode);
        $this->assertSame('RED-1', $variant->old_sku);
        $log = AuditLog::where('action', 'variant_delete')->sole();
        $this->assertSame('5000000000001', $log->old_values['barcode']);

        // The same codes are free again, on a new product and on a new variant.
        $this->create(['name' => 'Reuse', 'barcode' => '5000000000001', 'sku' => 'REUSE'])->assertSessionHasNoErrors();
        $this->create(['name' => 'Again', 'barcode' => '5000000000009'], [['barcode' => '5000000000001', 'sku' => 'RED-1']])
            ->assertSessionHasErrors('variants.0.barcode'); // taken by "Reuse" now
    }

    public function test_a_variant_with_stock_cannot_be_deleted_and_one_without_is_soft_deleted_and_released(): void
    {
        $product = Product::factory()->create(['stock_qty' => 0]);
        $held = self::variantWithStock($product, '5000000000002', 6);
        $empty = self::variantWithStock($product, '5000000000003', 0);
        $admin = $this->admin();

        $this->actingAs($admin)->put(route('products.update', $product), $this->updatePayload($product, [
            ['id' => $held->id, 'remove' => 1],
        ]))->assertSessionHasErrors('variants');
        $this->assertNull($held->fresh()->deleted_at);
        $this->assertEquals(6, $product->fresh()->stock_qty);

        $this->actingAs($admin)->put(route('products.update', $product), $this->updatePayload($product, [
            ['id' => $empty->id, 'remove' => 1],
        ]))->assertSessionHasNoErrors();
        $gone = ProductVariant::withTrashed()->find($empty->id);
        $this->assertNotNull($gone->deleted_at);
        $this->assertNull($gone->barcode);
        $this->assertSame('5000000000003', $gone->old_barcode);
    }

    public function test_rows_that_are_not_posted_are_left_alone(): void
    {
        $product = Product::factory()->create(['stock_qty' => 0]);
        self::variantWithStock($product, '5000000000005', 2);

        $this->actingAs($this->admin())->put(route('products.update', $product), $this->updatePayload($product, []))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, $product->variants()->count());
    }

    // --- not combinable -----------------------------------------------------------------------

    public function test_a_bundle_or_service_cannot_carry_variants(): void
    {
        $this->create(['type' => 'bundle'], [['barcode' => '6000000000001', 'label' => 'Red']])->assertSessionHasErrors('type');
        $this->create(['type' => 'service', 'name' => 'Svc', 'barcode' => '6000000000002'], [['barcode' => '6000000000003']])->assertSessionHasErrors('type');
        $this->assertSame(0, Product::count());

        // And a product that has variants cannot be turned into one.
        $product = Product::factory()->create(['stock_qty' => 0]);
        self::variantWithStock($product, '6000000000004', 0);
        $this->actingAs($this->admin())->put(route('products.update', $product), $this->updatePayload($product, [], ['type' => 'bundle']))
            ->assertSessionHasErrors('type');
    }

    public function test_a_product_with_variants_cannot_be_a_bundle_component(): void
    {
        $component = Product::factory()->create();
        self::variantWithStock($component, '6000000000005', 0);
        $bundle = Product::factory()->create(['type' => Product::TYPE_BUNDLE]);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        \App\Models\BundleItem::create(['bundle_product_id' => $bundle->id, 'component_id' => $component->id, 'qty' => 1]);
    }

    // --- backfill -----------------------------------------------------------------------------

    public function test_the_migration_releases_variants_of_products_deleted_before_it_existed(): void
    {
        $product = Product::factory()->create(['stock_qty' => 0]);
        $variant = self::variantWithStock($product, '7000000000001', 0, ['sku' => 'OLD-1']);
        $live = self::variantWithStock(Product::factory()->create(['stock_qty' => 0]), '7000000000002', 0);
        $product->delete(); // the old behaviour: product trashed, variant untouched

        $migration = require base_path('database/migrations/2026_11_12_000001_add_variant_support.php');
        $migration->up();
        $migration->up(); // idempotent

        $row = ProductVariant::withTrashed()->find($variant->id);
        $this->assertNull($row->barcode);
        $this->assertSame('7000000000001', $row->old_barcode);
        $this->assertSame('OLD-1', $row->old_sku);
        $this->assertNotNull($row->deleted_at);
        $this->assertSame('7000000000002', $live->fresh()->barcode); // live product untouched
    }

    // --- the form and the list ---------------------------------------------------------------

    public function test_the_form_offers_the_variants_section_on_create_and_shows_existing_variants_on_edit(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->get('/products/create')->assertOk()
            ->assertSee('Add variant')->assertSee('variantRows()', false);

        $product = Product::factory()->create(['stock_qty' => 5]);
        self::variantWithStock($product, '9000000000001', 3, ['label' => 'Lavender 250ml']);

        $this->actingAs($admin)->get(route('products.edit', $product))->assertOk()
            ->assertSee('9000000000001')->assertSee('Lavender 250ml')
            ->assertSee('Main item: ', false);
    }

    public function test_the_variant_section_and_badge_render_in_arabic(): void
    {
        $admin = User::factory()->admin()->create(['language' => 'ar']);
        $product = Product::factory()->create(['stock_qty' => 5]);
        self::variantWithStock($product, '9000000000002', 3);
        self::variantWithStock($product, '9000000000003', 0);

        $this->actingAs($admin)->get(route('products.edit', $product))->assertOk()
            ->assertSee('الأنواع')->assertSee('إضافة نوع')->assertSee('المنتج الأساسي');
        $this->actingAs($admin)->get('/products')->assertOk()->assertSee('2 أنواع');
    }

    public function test_the_list_badges_a_product_with_variants_and_a_variant_barcode_finds_its_product(): void
    {
        $withVariants = Product::factory()->create(['name' => 'Candle', 'stock_qty' => 5]);
        self::variantWithStock($withVariants, '9000000000004', 3);
        self::variantWithStock($withVariants, '9000000000005', 2);
        Product::factory()->create(['name' => 'Plain']);

        $admin = $this->admin();
        $this->actingAs($admin)->get('/products')->assertOk()->assertSee('2 variants');
        $this->actingAs($admin)->get('/products?search=9000000000005')->assertOk()->assertSee('Candle')->assertDontSee('Plain');
    }

    public function test_an_existing_product_can_edit_deactivate_and_add_variants(): void
    {
        $product = Product::factory()->create(['stock_qty' => 5]);
        $red = self::variantWithStock($product, '9100000000001', 3, ['label' => 'Red']);
        $total = fn () => (float) $product->fresh()->stock_qty;
        $this->assertEquals(8, $total());

        $this->actingAs($this->admin())->put(route('products.update', $product), $this->updatePayload($product, [
            ['id' => $red->id, 'barcode' => '9100000000001', 'label' => 'Crimson', 'is_active' => 0],
            ['barcode' => '9100000000002', 'label' => 'Blue', 'stock_qty' => 4],
        ]))->assertSessionHasNoErrors();

        $red = $red->fresh();
        $this->assertSame('Crimson', $red->label);
        $this->assertFalse($red->is_active);
        $this->assertSame('9100000000001', $red->barcode);         // deactivating keeps the barcode
        $this->assertEquals(3, (float) $red->stock_qty);           // and the stock
        $this->assertSame(1, AuditLog::where('action', 'variant_deactivate')->count());
        $this->assertEquals(12, $total());                         // Blue's 4 opened into the total
        $this->assertEquals(5, $product->fresh()->mainStockQty()); // the main item did not move
    }

    /** What the edit form posts for a product: its own fields plus the variant rows. */
    private function updatePayload(Product $product, array $variants, array $extra = []): array
    {
        return array_merge([
            'name' => $product->name,
            'category_id' => $product->category_id,
            'price_usd' => $product->price_usd,
            'variants' => $variants,
        ], $extra);
    }
}
