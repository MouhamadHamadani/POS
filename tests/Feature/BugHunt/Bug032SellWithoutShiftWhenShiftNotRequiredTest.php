<?php

namespace Tests\Feature\BugHunt;

use App\Models\Product;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BUG-032: with Settings > POS > "Require open shift" turned off, the till opens but every sale is refused.
 *
 * ShiftRequired middleware waves the cashier through to the sell screen when `require_shift` is off,
 * but SaleController::store() independently insists on an open shift and answers 409 "no open shift"
 * (and `sales.shift_id` is NOT NULL). The setting therefore changes nothing a cashier can do except
 * reach a screen on which nothing can be sold.
 */
class Bug032SellWithoutShiftWhenShiftNotRequiredTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_sale_can_be_completed_without_an_open_shift_when_shifts_are_not_required(): void
    {
        Setting::set('require_shift', '0', 'pos', 'bool');
        Setting::set('exchange_rate', 90000, 'currency', 'float');
        $cashier = User::factory()->create();
        $product = Product::factory()->create(['price_usd' => 5, 'stock_qty' => 10, 'is_taxable' => false]);

        $this->actingAs($cashier)->get(route('pos.sell'))->assertOk(); // the screen is reachable...

        $response = $this->actingAs($cashier)->postJson(route('pos.api.sales'), [
            'cart' => [['product_id' => $product->id, 'qty' => 1]],
            'payment' => ['method' => 'cash_usd', 'amount_usd' => 5],
        ]);

        $response->assertStatus(201); // ...so the sale must go through
        $this->assertSame(1, Sale::count());
    }
}
