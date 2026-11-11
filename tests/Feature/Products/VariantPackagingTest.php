<?php

namespace Tests\Feature\Products;

use App\Models\Category;
use App\Models\InventoryAdjustment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\User;
use App\Services\BarcodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * client/tobacco: variants (other barcodes of one product, own stock) and packaging (pack / package / carton) are
 * two ways to sell one product that never combine on it, and every barcode of both is one namespace.
 */
class VariantPackagingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['pos.features.packaging' => true]);
        Setting::set('exchange_rate', 90000, 'currency', 'float');
        Setting::set('lbp_rounding_step', 1000, 'currency', 'int');
    }

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    private function packaged(array $over = []): Product
    {
        return Product::factory()->create($over + [
            'barcode' => '5100000000017', 'stock_qty' => 0, 'has_package' => true, 'package_size' => 10, 'package_price_usd' => 75,
            'carton_barcode' => '5100000000024', 'packages_per_carton' => 20,
        ]);
    }

    private function payload(Product $p, array $variants, array $over = []): array
    {
        return $over + ['name' => $p->name, 'category_id' => $p->category_id, 'price_usd' => $p->price_usd, 'barcode' => $p->barcode, 'variants' => $variants];
    }

    public function test_a_packaged_product_cannot_get_variants(): void
    {
        $p = $this->packaged();
        $payload = $this->payload($p, [['barcode' => '5100000000100', 'label' => 'Red']], [
            'has_package' => 1, 'package_size' => 10, 'package_price_usd' => 75, 'carton_barcode' => '5100000000024', 'packages_per_carton' => 20,
        ]);

        $this->actingAs($this->admin())->put(route('products.update', $p), $payload)->assertSessionHasErrors('variants');
        $this->assertSame(0, ProductVariant::count());
    }

    public function test_a_product_with_variants_cannot_be_packaged(): void
    {
        $p = Product::factory()->create(['barcode' => '5100000000200', 'stock_qty' => 0]);
        ProductVariantsTest::variantWithStock($p, '5100000000201', 0);

        $this->actingAs($this->admin())->put(route('products.update', $p), $this->payload($p, [], [
            'has_package' => 1, 'package_size' => 10, 'package_price_usd' => 75,
        ]))->assertSessionHasErrors('has_package');
    }

    public function test_a_variant_barcode_and_a_carton_barcode_are_one_namespace(): void
    {
        $this->packaged(); // carton 5100000000024
        $owner = Product::factory()->create(['barcode' => '5100000000300', 'stock_qty' => 0]);
        $variant = ProductVariantsTest::variantWithStock($owner, '5100000000301', 0);
        $service = app(BarcodeService::class);

        $this->assertSame('carton', $service->findByBarcode('5100000000024')['kind']);
        $this->assertSame('variant', $service->findByBarcode('5100000000301')['kind']);

        // A variant cannot take a carton barcode, nor the carton barcode typed in the same form.
        $this->actingAs($this->admin())->put(route('products.update', $owner), $this->payload($owner, [['id' => $variant->id, 'barcode' => '5100000000024']]))
            ->assertSessionHasErrors('variants.0.barcode');
        $this->actingAs($this->admin())->put(route('products.update', $owner), $this->payload($owner, [['barcode' => '5100000000999']], ['carton_barcode' => '5100000000999']))
            ->assertSessionHasErrors();

        // And a new product cannot use a variant's barcode as its carton barcode.
        $this->actingAs($this->admin())->post('/products', [
            'name' => 'Other', 'category_id' => Category::factory()->create()->id, 'price_usd' => 5, 'barcode' => '5100000000400',
            'has_package' => 1, 'package_size' => 10, 'package_price_usd' => 40, 'carton_barcode' => '5100000000301', 'packages_per_carton' => 20,
        ])->assertSessionHasErrors('carton_barcode');
    }

    public function test_with_packaging_on_a_variant_sells_as_a_pack_from_its_own_stock(): void
    {
        $p = Product::factory()->create(['price_usd' => 10, 'stock_qty' => 10, 'is_taxable' => false, 'barcode' => '5100000000500']);
        $red = ProductVariantsTest::variantWithStock($p, '5100000000501', 6, ['label' => 'Red']);
        $cashier = User::factory()->create(['role' => User::ROLE_CASHIER]);
        Shift::create(['user_id' => $cashier->id, 'opened_at' => now(), 'opening_cash_usd' => 0, 'opening_cash_lbp' => 0, 'status' => Shift::STATUS_OPEN]);

        $this->actingAs($cashier)->postJson('/pos/api/sales', [
            'cart' => [['product_id' => $p->id, 'product_variant_id' => $red->id, 'sale_unit' => 'pack', 'qty' => 2]],
            'payment' => ['amount_usd' => 100],
        ])->assertCreated();

        $item = SaleItem::sole();
        $this->assertSame($red->id, $item->product_variant_id);
        $this->assertSame('pack', $item->sale_unit);
        $this->assertEquals(2, (float) $item->base_qty);
        $this->assertEquals(20, (float) $item->line_total_usd);
        $this->assertEquals(4, (float) $red->fresh()->stock_qty);
        $this->assertEquals(14, (float) $p->fresh()->stock_qty);   // 10 main + 4 left on Red
        $this->assertEquals(10, $p->fresh()->mainStockQty());

        // A package line of a product that has no packaging is refused whatever the variant says.
        $this->actingAs($cashier)->postJson('/pos/api/sales', [
            'cart' => [['product_id' => $p->id, 'product_variant_id' => $red->id, 'sale_unit' => 'package', 'qty' => 1]],
            'payment' => ['amount_usd' => 100],
        ])->assertStatus(422);
        $this->assertSame(1, Sale::count());
    }

    public function test_the_till_lookup_of_a_variant_code_is_a_pack_scan_with_the_variant_named(): void
    {
        $p = Product::factory()->create(['barcode' => '5100000000600', 'stock_qty' => 0]);
        $variant = ProductVariantsTest::variantWithStock($p, '5100000000601', 3);
        $user = $this->admin();
        Shift::create(['user_id' => $user->id, 'opened_at' => now(), 'opening_cash_usd' => 0, 'opening_cash_lbp' => 0, 'status' => Shift::STATUS_OPEN]);

        $this->actingAs($user)->getJson('/pos/api/barcode?code=5100000000601')
            ->assertOk()->assertJsonPath('id', $p->id)->assertJsonPath('variant_id', $variant->id)->assertJsonPath('barcode_kind', 'pack');
    }

    public function test_the_sell_screen_keeps_one_cart_line_per_product_variant_and_unit(): void
    {
        $user = $this->admin();
        Shift::create(['user_id' => $user->id, 'opened_at' => now(), 'opening_cash_usd' => 0, 'opening_cash_lbp' => 0, 'status' => Shift::STATUS_OPEN]);

        $this->actingAs($user)->get('/pos')->assertOk()
            ->assertSee("l.product_id + ':' + (l.variant_id ?? 0) + ':' + (l.sale_unit || 'pack')", false)
            ->assertSee('scanHit(hit, raw)', false)
            ->assertSee('addToCart(p, unit, v = null)', false)
            ->assertSee('pkgScanHit', false);
    }
}
