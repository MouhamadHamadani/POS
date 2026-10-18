<?php

namespace Tests\Feature\Products;

use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BUG-034: the LBP price input steps by the rounding step (BUG-024) unless the value it holds is not a
 * multiple of it, in which case it is `any` so the browser cannot refuse the whole product form.
 */
class ProductLbpPriceOffStepTest extends TestCase
{
    use RefreshDatabase;

    private function editStep(Product $product): string
    {
        $html = $this->actingAs(User::factory()->admin()->create())->get(route('products.edit', $product))->assertOk()->getContent();
        $this->assertSame(1, preg_match('/<input[^>]*name="price_lbp"[^>]*>/', $html, $m));
        $this->assertSame(1, preg_match('/\sstep="([^"]*)"/', $m[0], $s));

        return $s[1];
    }

    public function test_a_stored_price_on_the_step_keeps_the_rounding_step(): void
    {
        Setting::set('lbp_rounding_step', 5000, 'currency', 'float');

        $this->assertSame('5000', $this->editStep(Product::factory()->create(['price_lbp' => 15000])));
    }

    public function test_a_product_without_an_lbp_price_keeps_the_rounding_step(): void
    {
        Setting::set('lbp_rounding_step', 500, 'currency', 'float');

        $this->assertSame('500', $this->editStep(Product::factory()->create(['price_lbp' => null])));
    }

    public function test_a_stored_price_off_the_step_is_any(): void
    {
        Setting::set('lbp_rounding_step', 5000, 'currency', 'float');

        $this->assertSame('any', $this->editStep(Product::factory()->create(['price_lbp' => 12000])));
    }
}
