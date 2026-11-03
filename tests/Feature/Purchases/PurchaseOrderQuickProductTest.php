<?php

namespace Tests\Feature\Purchases;

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\InventoryAdjustment;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Shift;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** "+ New product" on the PO form, and the incomplete flag that keeps such a product off the till. */
class PurchaseOrderQuickProductTest extends TestCase
{
    use RefreshDatabase;

    private function incompleteProduct(array $attrs = []): Product
    {
        return Product::factory()->create(['price_usd' => 0, 'is_incomplete' => true] + $attrs);
    }

    private function cashierWithShift(): User
    {
        $user = User::factory()->create();
        Shift::create([
            'user_id' => $user->id, 'opened_at' => now(),
            'opening_cash_usd' => 0, 'opening_cash_lbp' => 0, 'status' => Shift::STATUS_OPEN,
        ]);
        return $user;
    }

    public function test_quick_create_returns_the_picker_shape_and_saves_an_unpriced_incomplete_product(): void
    {
        $category = Category::factory()->create();
        $vat = Tax::create(['name' => 'VAT', 'rate' => 0.11, 'is_inclusive' => false, 'is_default' => true, 'is_active' => true]);
        $user = User::factory()->stock()->create();

        $res = $this->actingAs($user)->postJson(route('products.quick-store'), [
            'name' => 'Olive oil 1L', 'name_ar' => 'زيت زيتون', 'category_id' => $category->id,
            'tax_id' => $vat->id, 'cost_usd' => 4.5,
        ])->assertCreated()->assertJsonStructure(['id', 'name', 'sku', 'cost_usd', 'tax' => ['rate'], 'unit']);

        $this->assertSame('Olive oil 1L', $res->json('name'));
        $this->assertSame('pcs', $res->json('unit'));
        $this->assertEqualsWithDelta(0.11, (float) $res->json('tax.rate'), 0.00001);

        $product = Product::findOrFail($res->json('id'));
        $this->assertTrue($product->is_incomplete);
        $this->assertTrue($product->is_active);
        $this->assertTrue($product->track_stock);
        $this->assertTrue($product->is_taxable);
        $this->assertSame(Product::TYPE_SIMPLE, $product->type);
        $this->assertSame(0.0, (float) $product->price_usd);
        $this->assertSame(4.5, (float) $product->cost_usd);
        $this->assertSame(0.0, (float) $product->stock_qty);
        $this->assertNull($product->sku);
        $this->assertMatchesRegularExpression('/^\d{13}$/', $product->barcode);
        $this->assertSame($user->id, $product->created_by);
        $this->assertSame(0, InventoryAdjustment::count());

        $log = AuditLog::where('action', 'create')->where('model_type', Product::class)->where('model_id', $product->id)->sole();
        $this->assertSame('purchase_order_form', $log->new_values['source']);
    }

    public function test_without_a_tax_the_product_is_untaxed_and_the_json_tax_is_null(): void
    {
        $category = Category::factory()->create();

        $res = $this->actingAs(User::factory()->manager()->create())->postJson(route('products.quick-store'), [
            'name' => 'Freight box', 'category_id' => $category->id, 'unit' => 'box',
        ])->assertCreated()->assertJsonPath('tax', null)->assertJsonPath('unit', 'box');

        $product = Product::findOrFail($res->json('id'));
        $this->assertFalse($product->is_taxable);
        $this->assertSame(0.0, (float) $product->cost_usd);
    }

    public function test_a_cashier_cannot_quick_create(): void
    {
        $category = Category::factory()->create();

        $this->actingAs(User::factory()->create())->postJson(route('products.quick-store'), [
            'name' => 'Nope', 'category_id' => $category->id,
        ])->assertForbidden();

        $this->assertSame(0, Product::count());
    }

    public function test_a_missing_name_or_category_is_a_422(): void
    {
        $this->actingAs(User::factory()->admin()->create())->postJson(route('products.quick-store'), [])
            ->assertStatus(422)->assertJsonValidationErrors(['name', 'category_id']);

        $this->assertSame(0, Product::count());
    }

    public function test_an_incomplete_product_is_kept_off_every_sell_side_lookup(): void
    {
        $cashier = $this->cashierWithShift();
        $sellable = Product::factory()->create(['name' => 'Ready widget']);
        $incomplete = $this->incompleteProduct(['name' => 'Unpriced widget', 'barcode' => '5901234123457']);

        $this->actingAs($cashier)->get(route('pos.sell'))->assertOk()
            ->assertViewHas('products', fn ($products) => $products->contains('id', $sellable->id) && ! $products->contains('id', $incomplete->id));

        $ids = collect($this->getJson('/pos/api/products?search=widget')->assertOk()->json())->pluck('id');
        $this->assertTrue($ids->contains($sellable->id));
        $this->assertFalse($ids->contains($incomplete->id));

        $this->getJson('/pos/api/barcode?code=5901234123457')->assertNotFound();

        // A posted id is refused by the server too, so it can never ring up at $0.
        $this->postJson('/pos/api/sales', [
            'cart' => [['product_id' => $incomplete->id, 'qty' => 1]],
            'payment' => ['amount_usd' => 0],
        ])->assertStatus(422)->assertJsonPath('error.cart.0', __('messages.product_incomplete', ['product' => 'Unpriced widget']));
        $this->assertSame(0, Sale::count());
    }

    public function test_an_incomplete_product_is_in_the_po_picker_and_the_products_list(): void
    {
        $manager = User::factory()->manager()->create();
        Product::factory()->create(['name' => 'Ready widget']);
        $incomplete = $this->incompleteProduct(['name' => 'Unpriced widget']);

        $this->actingAs($manager)->get(route('purchases.create'))->assertOk()
            ->assertViewHas('products', fn ($products) => $products->contains('id', $incomplete->id));

        $this->get(route('products.index'))->assertOk()
            ->assertSee('Unpriced widget')->assertSee(__('products.incomplete'));

        $this->get(route('products.index', ['incomplete' => 1]))->assertOk()
            ->assertSee('Unpriced widget')->assertDontSee('Ready widget');
    }

    public function test_saving_the_product_with_a_sell_price_clears_the_flag(): void
    {
        $manager = User::factory()->manager()->create();
        $product = $this->incompleteProduct();
        $payload = ['name' => $product->name, 'category_id' => $product->category_id, 'is_active' => 1, 'track_stock' => 1];

        // Still no price: stays incomplete.
        $this->actingAs($manager)->put(route('products.update', $product), $payload + ['price_usd' => 0])->assertSessionHasNoErrors();
        $this->assertTrue($product->fresh()->is_incomplete);

        $this->put(route('products.update', $product), $payload + ['price_usd' => 3.5])->assertSessionHasNoErrors();
        $this->assertFalse($product->fresh()->is_incomplete);
    }
}
