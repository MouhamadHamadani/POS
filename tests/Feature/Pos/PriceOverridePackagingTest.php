<?php

namespace Tests\Feature\Pos;

use App\Models\Product;
use App\Models\SaleItem;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A cashier price override on a pack / package / carton line (client/tobacco). The list price a change is
 * measured against is the UNIT's own price, never the pack's: a package at its list price is no override
 * at all, and a 10% cut of a $75 package is $67.50, not a 740% rise over the $8 pack.
 */
class PriceOverridePackagingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['pos.features.packaging' => true]);
    }

    private function cashier(): User
    {
        $user = User::factory()->create(['role' => User::ROLE_CASHIER]);
        Shift::create(['user_id' => $user->id, 'opened_at' => now(), 'opening_cash_usd' => 0, 'opening_cash_lbp' => 0, 'status' => Shift::STATUS_OPEN]);

        return $user;
    }

    private function tobacco(): Product
    {
        return Product::factory()->create([
            'name' => 'Marlboro Red', 'barcode' => '5000000000017',
            'price_usd' => 8, 'cost_usd' => 5, 'stock_qty' => 1000, 'is_taxable' => false,
            'has_package' => true, 'package_size' => 10, 'package_price_usd' => 75,
            'carton_barcode' => '5000000000024', 'packages_per_carton' => 20, 'carton_price_usd' => 1400, 'sell_carton_at_pos' => true,
        ]);
    }

    private function sell(User $user, array $line)
    {
        return $this->actingAs($user)->postJson(route('pos.api.sales'), [
            'cart' => [$line],
            'payment' => ['amount_usd' => 5000],
            'price_tier' => 'retail',
        ]);
    }

    public function test_a_package_at_its_own_list_price_is_not_an_override(): void
    {
        $product = $this->tobacco();

        $this->sell($this->cashier(), ['product_id' => $product->id, 'qty' => 1, 'sale_unit' => 'package', 'unit_price' => 75])->assertCreated();

        $item = SaleItem::sole();
        $this->assertEquals(75, $item->unit_price_usd);
        $this->assertNull($item->price_overridden_by);
    }

    public function test_a_small_cut_on_a_package_is_measured_against_the_package_price(): void
    {
        $product = $this->tobacco();
        $cashier = $this->cashier();

        $this->sell($cashier, [
            'product_id' => $product->id, 'qty' => 2, 'sale_unit' => 'package', 'unit_price' => 67.5, 'price_reason' => 'regular customer',
        ])->assertCreated();

        $item = SaleItem::sole();
        $this->assertEquals(67.5, $item->unit_price_usd);
        $this->assertEquals(75, $item->original_unit_price_usd);
        $this->assertSame($cashier->id, $item->price_overridden_by);
        $this->assertEquals(135, $item->line_total_usd);
        $this->assertEquals(980, $product->fresh()->stock_qty, 'stock still moves by packs: 2 packages = 20 packs');
    }

    public function test_a_cut_past_the_cashiers_limit_on_a_carton_needs_a_manager(): void
    {
        $product = $this->tobacco();

        // 30% off the $1400 carton, past the default 20% limit.
        $this->sell($this->cashier(), [
            'product_id' => $product->id, 'qty' => 1, 'sale_unit' => 'carton', 'unit_price' => 980, 'price_reason' => 'bulk',
        ])->assertStatus(422)->assertJsonStructure(['error' => ['cart.0.price']]);

        $this->assertSame(0, SaleItem::count());
    }
}
