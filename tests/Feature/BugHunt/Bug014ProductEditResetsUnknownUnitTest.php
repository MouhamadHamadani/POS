<?php

namespace Tests\Feature\BugHunt;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\BugHunt\Concerns\SubmitsRenderedForms;
use Tests\TestCase;

/**
 * BUG-014: saving the product edit form overwrites a unit that is not in the dropdown with "pcs".
 *
 * The Unit <select> offers nine fixed values, but `unit` is a free string everywhere else:
 * bulk import (ProductImportService / StoreProductRequest) accepts any value up to 20
 * characters, and the sell screen shows an unknown unit as-is. For such a product the
 * rendered select has no selected option, so the browser submits the first one ("pcs")
 * and an unrelated edit (a new price) silently changes "carton" to "pcs".
 */
class Bug014ProductEditResetsUnknownUnitTest extends TestCase
{
    use RefreshDatabase;
    use SubmitsRenderedForms;

    public function test_editing_a_product_whose_unit_is_not_in_the_dropdown_keeps_its_unit(): void
    {
        $product = Product::factory()->create(['unit' => 'carton', 'price_usd' => 10, 'cost_usd' => 4]);
        $admin = User::factory()->admin()->create();

        $html = $this->actingAs($admin)->get(route('products.edit', $product))->assertOk()->getContent();

        // The user only changes the price; price_usd / cost_usd are Alpine-filled, not value attributes.
        $this->submitRenderedForm($html, route('products.update', $product), set: [
            'price_usd' => '12', 'cost_usd' => '4',
        ])->assertSessionHasNoErrors();

        $this->assertSame('carton', $product->fresh()->unit, 'The unit was reset by an edit that did not touch it.');
    }
}
