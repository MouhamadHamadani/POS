<?php

namespace Tests\Feature\Products;

use App\Models\Product;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\BugHunt\Concerns\SubmitsRenderedForms;
use Tests\TestCase;

/**
 * Once a tax rate can be deactivated (BUG-004) the product form must not lose it: a product
 * that is still on a deactivated rate keeps it selected on edit, and the rate is not offered
 * to a new product.
 */
class ProductInactiveTaxKeptTest extends TestCase
{
    use RefreshDatabase;
    use SubmitsRenderedForms;

    public function test_editing_a_product_on_a_deactivated_tax_keeps_that_tax(): void
    {
        $old = Tax::factory()->create(['name' => 'Old VAT', 'rate' => 0.10, 'is_active' => false]);
        $product = Product::factory()->create(['tax_id' => $old->id, 'price_usd' => 10, 'cost_usd' => 4]);
        $admin = User::factory()->admin()->create();

        $html = $this->actingAs($admin)->get(route('products.edit', $product))->assertOk()->getContent();

        $this->submitRenderedForm($html, route('products.update', $product), set: ['price_usd' => '12', 'cost_usd' => '4'])
            ->assertSessionHasNoErrors();

        $this->assertSame($old->id, $product->fresh()->tax_id, 'Saving the form stripped the deactivated tax.');
    }

    public function test_a_deactivated_tax_is_not_offered_to_a_new_product_or_one_on_another_tax(): void
    {
        $old = Tax::factory()->create(['name' => 'Retired VAT', 'is_active' => false]);
        $live = Tax::factory()->create(['name' => 'Live VAT', 'is_active' => true]);
        $other = Product::factory()->create(['tax_id' => $live->id]);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('products.create'))->assertOk()->assertDontSee('Retired VAT');
        $this->actingAs($admin)->get(route('products.edit', $other))->assertOk()->assertDontSee('Retired VAT');
    }
}
