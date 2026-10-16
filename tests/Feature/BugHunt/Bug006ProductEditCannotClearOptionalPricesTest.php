<?php

namespace Tests\Feature\BugHunt;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\BugHunt\Concerns\SubmitsRenderedForms;
use Tests\TestCase;

/**
 * BUG-006: clearing an optional price (or max stock) on the product edit form is silently ignored.
 *
 * wholesale_price_usd, vip_price_usd, price_lbp and max_stock are nullable columns, and
 * Product::priceForGroup() falls back to the retail price when the tier price is null.
 * StoreProductRequest::prepareForValidation() drops any numeric field that arrives blank
 * ("so the model default kicks in"), which is right on create but on update means the old
 * value is kept. The admin saves, sees "Product updated", and wholesale customers are
 * still charged the old wholesale price.
 */
class Bug006ProductEditCannotClearOptionalPricesTest extends TestCase
{
    use RefreshDatabase;
    use SubmitsRenderedForms;

    public function test_blanking_the_wholesale_and_vip_price_on_the_edit_form_clears_them(): void
    {
        $product = Product::factory()->create([
            'price_usd' => 10, 'cost_usd' => 3,
            'wholesale_price_usd' => 8, 'vip_price_usd' => 7, 'price_lbp' => 900000, 'max_stock' => 100,
        ]);
        $admin = User::factory()->admin()->create();

        $html = $this->actingAs($admin)->get(route('products.edit', $product))->assertOk()->getContent();

        // price_usd / cost_usd are filled in by Alpine (x-model) in the browser, not by a value attribute.
        $this->submitRenderedForm($html, route('products.update', $product), set: [
            'price_usd' => '10', 'cost_usd' => '3',
            'wholesale_price_usd' => '', 'vip_price_usd' => '', 'price_lbp' => '', 'max_stock' => '',
        ])->assertSessionHasNoErrors();

        $fresh = $product->fresh();
        $this->assertNull($fresh->wholesale_price_usd, 'Wholesale price kept its old value after being cleared.');
        $this->assertNull($fresh->vip_price_usd, 'VIP price kept its old value after being cleared.');
        $this->assertNull($fresh->price_lbp, 'LBP price kept its old value after being cleared.');
        $this->assertNull($fresh->max_stock, 'Max stock kept its old value after being cleared.');
        $this->assertSame(10.0, $fresh->priceForGroup('wholesale'), 'Wholesale customers should now pay the retail price.');
    }
}
