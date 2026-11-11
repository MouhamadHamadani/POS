<?php

namespace Tests\Feature\Pos;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Products\ProductVariantsTest;
use Tests\TestCase;

/** The sell screen and its barcode lookup see variants: the whole barcode namespace, no cost, no extra price. */
class VariantTillTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;
    private ProductVariant $red;

    protected function setUp(): void
    {
        parent::setUp();
        $this->product = Product::factory()->create(['name' => 'Soap', 'barcode' => '1230000000001', 'stock_qty' => 10, 'cost_usd' => 4.1234]);
        $this->red = ProductVariantsTest::variantWithStock($this->product, '1230000000002', 6, ['label' => 'Red']);
        ProductVariantsTest::variantWithStock($this->product, '1230000000003', 2, ['label' => 'Blue']);
    }

    private function till(string $role = User::ROLE_CASHIER): User
    {
        $user = User::factory()->create(['role' => $role]);
        Shift::create(['user_id' => $user->id, 'opened_at' => now(), 'opening_cash_usd' => 0, 'opening_cash_lbp' => 0, 'status' => Shift::STATUS_OPEN]);

        return $user;
    }

    /** The product list the page hands to Alpine: window data is the JSON after `allProducts:`. */
    private function pageProducts(User $user): array
    {
        $html = $this->actingAs($user)->get('/pos')->assertOk()->getContent();
        preg_match('/allProducts:\s*(\[.*?\]),\n\s*categories:/s', $html, $m);

        return json_decode(html_entity_decode($m[1]), true) ?? [];
    }

    public function test_the_sell_screen_ships_each_products_active_variants_and_the_main_items_own_stock(): void
    {
        ProductVariantsTest::variantWithStock($this->product, '1230000000004', 5, ['label' => 'Hidden', 'is_active' => false]);

        $row = collect($this->pageProducts($this->till()))->firstWhere('id', $this->product->id);

        $this->assertEquals(23, $row['stock_qty']);   // 10 main + 6 + 2 + 5 inactive
        $this->assertEquals(10, $row['main_stock']);  // the inactive variant's stock is still not the main item's
        $this->assertSame(['Soap — Red', 'Soap — Blue'], collect($row['variants'])->pluck('display_name')->all());
        $this->assertSame(['1230000000002', '1230000000003'], collect($row['variants'])->pluck('barcode')->all());
        $this->assertArrayNotHasKey('price_modifier', $row['variants'][0]);
        $this->assertArrayNotHasKey('variants_stock', $row);
    }

    public function test_a_cashier_never_receives_cost_but_a_manager_does(): void
    {
        $cashier = collect($this->pageProducts($this->till()))->firstWhere('id', $this->product->id);
        $this->assertArrayNotHasKey('cost_usd', $cashier);

        $manager = collect($this->pageProducts($this->till(User::ROLE_MANAGER)))->firstWhere('id', $this->product->id);
        $this->assertEquals(4.1234, $manager['cost_usd']);
    }

    public function test_the_lookup_resolves_a_variant_code_to_its_product_and_the_variant(): void
    {
        $this->actingAs($this->till())->getJson('/pos/api/barcode?code=1230000000002')
            ->assertOk()
            ->assertJsonPath('id', $this->product->id)
            ->assertJsonPath('variant_id', $this->red->id)
            ->assertJsonPath('main_stock', 10)
            ->assertJsonPath('variants.0.display_name', 'Soap — Red')
            ->assertJsonMissingPath('cost_usd');
    }

    public function test_the_lookup_of_the_main_barcode_has_no_variant_id(): void
    {
        $this->actingAs($this->till())->getJson('/pos/api/barcode?code=1230000000001')
            ->assertOk()->assertJsonPath('id', $this->product->id)->assertJsonPath('variant_id', null);
    }

    public function test_an_inactive_variant_says_so_and_a_deleted_one_is_not_found(): void
    {
        $user = $this->till();
        $this->red->update(['is_active' => false]);
        $this->actingAs($user)->getJson('/pos/api/barcode?code=1230000000002')
            ->assertStatus(422)->assertJsonPath('error', 'This variant is inactive.');

        $this->red->update(['barcode' => null]);
        $this->red->delete();
        $this->actingAs($user)->getJson('/pos/api/barcode?code=1230000000002')->assertStatus(404);
    }

    public function test_a_variant_of_an_inactive_product_is_not_sold(): void
    {
        $this->product->update(['is_active' => false]);

        $this->actingAs($this->till())->getJson('/pos/api/barcode?code=1230000000003')->assertStatus(404);
    }

    public function test_the_inactive_message_is_in_arabic_for_an_arabic_till(): void
    {
        $user = $this->till();
        $user->update(['language' => 'ar']);
        $this->red->update(['is_active' => false]);

        $this->actingAs($user)->getJson('/pos/api/barcode?code=1230000000002')
            ->assertStatus(422)->assertJsonPath('error', 'هذا النوع غير مفعّل.');
    }

    public function test_the_sell_screen_ships_the_chooser_and_keeps_the_quantity_shortcut(): void
    {
        $this->actingAs($this->till())->get('/pos')->assertOk()
            ->assertSee('tapProduct(p)', false)
            ->assertSee('chooserKey($event)', false)
            ->assertSee('findByCode(raw)', false)
            ->assertSee('setQty(this.lastScannedIndex, parseInt(raw, 10))', false)
            ->assertSee('Press a number to pick');
    }
}
