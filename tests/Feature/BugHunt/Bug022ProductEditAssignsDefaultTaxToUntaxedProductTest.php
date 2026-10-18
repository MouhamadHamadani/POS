<?php

namespace Tests\Feature\BugHunt;

use App\Models\Product;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\BugHunt\Concerns\SubmitsRenderedForms;
use Tests\TestCase;

/**
 * BUG-022: opening and saving the edit form of a product that has no tax assigns it the default VAT.
 *
 * products/form.blade.php preselects `old('tax_id', $product->tax_id ?? $defaultTaxId)`.
 * The `?? $defaultTaxId` is right for a brand-new product, but on edit a product whose tax_id
 * is NULL ("No tax" — a tax-free item, or any product imported without a tax column) is
 * rendered with the default tax selected. The next Save, even just to correct a price typo,
 * attaches 11% VAT, and SaleService (which charges tax only when product->tax exists) starts
 * adding 11% at the till.
 */
class Bug022ProductEditAssignsDefaultTaxToUntaxedProductTest extends TestCase
{
    use RefreshDatabase;
    use SubmitsRenderedForms;

    public function test_saving_an_edit_keeps_a_product_without_tax_untaxed(): void
    {
        Tax::factory()->default()->create(['name' => 'VAT 11%', 'rate' => 0.11]);
        $product = Product::factory()->create(['tax_id' => null, 'price_usd' => 10, 'cost_usd' => 4]);
        $admin = User::factory()->admin()->create();

        $html = $this->actingAs($admin)->get(route('products.edit', $product))->assertOk()->getContent();

        // The user only fixes the price; price_usd / cost_usd are Alpine-filled, not value attributes.
        $this->submitRenderedForm($html, route('products.update', $product), set: [
            'price_usd' => '12', 'cost_usd' => '4',
        ])->assertSessionHasNoErrors();

        $this->assertNull($product->fresh()->tax_id, 'The edit attached the default VAT to a product that had none.');
    }
}
