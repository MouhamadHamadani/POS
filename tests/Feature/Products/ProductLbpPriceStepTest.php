<?php

namespace Tests\Feature\Products;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** BUG-024: the product form's LBP price input steps by the store's LBP rounding step. */
class ProductLbpPriceStepTest extends TestCase
{
    use RefreshDatabase;

    private function step(): string
    {
        $html = $this->actingAs(User::factory()->admin()->create())->get(route('products.create'))->assertOk()->getContent();
        $this->assertSame(1, preg_match('/<input[^>]*name="price_lbp"[^>]*>/', $html, $m));
        $this->assertSame(1, preg_match('/\sstep="([^"]*)"/', $m[0], $s));

        return $s[1];
    }

    public function test_the_step_follows_the_rounding_step(): void
    {
        Setting::set('lbp_rounding_step', 100, 'currency', 'float');
        $this->assertSame('100', $this->step());
    }

    public function test_no_rounding_means_any_amount(): void
    {
        Setting::set('lbp_rounding_step', 0, 'currency', 'float');
        $this->assertSame('any', $this->step());
    }
}
