<?php

namespace Tests\Feature\Products;

use App\Models\Category;
use App\Models\Product;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductCrudTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    public function test_admin_can_view_product_list(): void
    {
        $this->actingAs($this->admin())->get('/products')->assertOk();
    }

    public function test_create_form_renders(): void
    {
        $this->actingAs($this->admin())->get('/products/create')->assertOk()
            ->assertSee('Basic Info')
            ->assertSee('Pricing');
    }

    public function test_creating_a_minimum_product_succeeds(): void
    {
        $cat = Category::factory()->create();
        $admin = $this->admin();

        $response = $this->actingAs($admin)->post('/products', [
            'name' => 'Coca Cola 1L',
            'category_id' => $cat->id,
            'price_usd' => 1.50,
            // Everything else left blank — this is the bug-prone case
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('products.index'));

        $this->assertDatabaseHas('products', [
            'name' => 'Coca Cola 1L',
            'category_id' => $cat->id,
        ]);
    }

    public function test_blank_optional_numeric_fields_default_correctly(): void
    {
        $cat = Category::factory()->create();
        $admin = $this->admin();

        // This mirrors what a real browser form posts: blank inputs become
        // empty strings (then ConvertEmptyStringsToNull makes them null).
        $this->actingAs($admin)->post('/products', [
            'name' => 'Blank Test',
            'category_id' => $cat->id,
            'price_usd' => 5.00,
            'cost_usd' => '',
            'stock_qty' => '',
            'min_stock' => '',
            'max_stock' => '',
            'unit' => '',
            'sku' => '',
            'barcode' => '',
            'name_ar' => '',
            'description' => '',
            'wholesale_price_usd' => '',
            'vip_price_usd' => '',
            'price_lbp' => '',
            'location' => '',
            'tax_id' => '',
        ])->assertSessionHasNoErrors();

        $product = Product::where('name', 'Blank Test')->first();
        $this->assertNotNull($product);
        $this->assertSame('0.0000', (string) $product->stock_qty);
        $this->assertSame('0.0000', (string) $product->min_stock);
        $this->assertSame('0.0000', (string) $product->cost_usd);
        $this->assertSame('pcs', $product->unit);
    }

    public function test_barcode_is_auto_generated_when_blank(): void
    {
        $cat = Category::factory()->create();

        $this->actingAs($this->admin())->post('/products', [
            'name' => 'Auto Barcode',
            'category_id' => $cat->id,
            'price_usd' => 1.00,
        ]);

        $product = Product::where('name', 'Auto Barcode')->first();
        $this->assertNotNull($product->barcode);
        $this->assertMatchesRegularExpression('/^\d{13}$/', $product->barcode);
    }

    public function test_explicit_barcode_is_preserved(): void
    {
        $cat = Category::factory()->create();

        $this->actingAs($this->admin())->post('/products', [
            'name' => 'Manual Barcode',
            'category_id' => $cat->id,
            'price_usd' => 1.00,
            'barcode' => '5901234123457',
        ]);

        $this->assertDatabaseHas('products', [
            'name' => 'Manual Barcode',
            'barcode' => '5901234123457',
        ]);
    }

    public function test_duplicate_sku_is_rejected(): void
    {
        $cat = Category::factory()->create();
        $existing = Product::factory()->create(['sku' => 'SKU-DUP']);

        $this->actingAs($this->admin())->post('/products', [
            'name' => 'Dup SKU',
            'category_id' => $cat->id,
            'price_usd' => 1,
            'sku' => 'SKU-DUP',
        ])->assertSessionHasErrors('sku');
    }

    public function test_cashier_cannot_access_products(): void
    {
        $cashier = User::factory()->create(['role' => User::ROLE_CASHIER]);
        $this->actingAs($cashier)->get('/products')->assertForbidden();
        $this->actingAs($cashier)->post('/products', [
            'name' => 'X', 'category_id' => 1, 'price_usd' => 1,
        ])->assertForbidden();
    }

    public function test_product_can_be_edited(): void
    {
        $product = Product::factory()->create(['name' => 'Old']);
        $admin = $this->admin();

        $this->actingAs($admin)->put("/products/{$product->id}", [
            'name' => 'New',
            'category_id' => $product->category_id,
            'price_usd' => 9.99,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame('New', $product->fresh()->name);
        $this->assertSame('9.9900', (string) $product->fresh()->price_usd);
    }

    public function test_image_upload_is_stored(): void
    {
        Storage::fake('public');
        $cat = Category::factory()->create();

        $this->actingAs($this->admin())->post('/products', [
            'name' => 'With Image',
            'category_id' => $cat->id,
            'price_usd' => 1.00,
            'image' => UploadedFile::fake()->image('product.jpg', 300, 300),
        ])->assertSessionHasNoErrors();

        $product = Product::where('name', 'With Image')->first();
        $this->assertNotNull($product->image);
        Storage::disk('public')->assertExists($product->image);
    }

    public function test_soft_delete_works(): void
    {
        $product = Product::factory()->create();

        $this->actingAs($this->admin())->delete("/products/{$product->id}")
            ->assertRedirect();

        $this->assertSoftDeleted('products', ['id' => $product->id]);
    }

    public function test_stock_adjustment_add_increases_qty(): void
    {
        $product = Product::factory()->create(['stock_qty' => 10]);

        $this->actingAs($this->admin())->post("/products/{$product->id}/adjust-stock", [
            'action' => 'add',
            'qty' => 5,
            'reason' => 'Supplier delivery',
        ])->assertRedirect();

        $this->assertSame('15.0000', (string) $product->fresh()->stock_qty);
    }

    public function test_stock_adjustment_remove_below_zero_is_rejected(): void
    {
        $product = Product::factory()->create(['stock_qty' => 3]);

        $this->actingAs($this->admin())->post("/products/{$product->id}/adjust-stock", [
            'action' => 'remove',
            'qty' => 10,
            'reason' => 'oversell test',
        ])->assertSessionHasErrors('stock');

        $this->assertSame('3.0000', (string) $product->fresh()->stock_qty);
    }
}
