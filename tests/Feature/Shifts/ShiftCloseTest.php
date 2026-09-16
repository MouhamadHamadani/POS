<?php

namespace Tests\Feature\Shifts;

use App\Models\Product;
use App\Models\Sale;
use App\Models\Shift;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Shift close: denomination counting, expected-vs-counted variance, and the
 * gate that stops selling once the shift is closed.
 */
class ShiftCloseTest extends TestCase
{
    use RefreshDatabase;

    private function openShift(User $user, float $usd = 100, float $lbp = 0): Shift
    {
        return Shift::create([
            'user_id' => $user->id,
            'opened_at' => now(),
            'opening_cash_usd' => $usd,
            'opening_cash_lbp' => $lbp,
            'status' => Shift::STATUS_OPEN,
        ]);
    }

    private function cashier(): User
    {
        return User::factory()->create(['role' => User::ROLE_CASHIER]);
    }

    /** Sells one 50.00 item + 11% VAT = 55.50, paid with 100.00 cash (44.50 change). */
    private function sellFiftyFive(User $cashier): Sale
    {
        $vat = Tax::create([
            'name' => 'Lebanese VAT', 'rate' => 0.11, 'is_inclusive' => false,
            'is_default' => true, 'is_active' => true,
        ]);
        $product = Product::factory()->create([
            'price_usd' => 50.00, 'cost_usd' => 20.00, 'stock_qty' => 10,
            'tax_id' => $vat->id, 'is_taxable' => true,
        ]);

        $id = $this->actingAs($cashier)->postJson('/pos/api/sales', [
            'cart' => [['product_id' => $product->id, 'qty' => 1, 'unit_price' => 50.00]],
            'payment' => ['method' => 'cash_usd', 'amount_usd' => 100.00],
        ])->assertCreated()->json('sale.id');

        return Sale::findOrFail($id);
    }

    public function test_close_computes_expected_cash_and_variance_from_the_denomination_count(): void
    {
        $cashier = $this->cashier();
        $shift = $this->openShift($cashier, usd: 100);

        $sale = $this->sellFiftyFive($cashier);
        $this->assertEqualsWithDelta(55.50, (float) $sale->total_usd, 0.0001);
        $this->assertEqualsWithDelta(44.50, (float) $sale->change_usd, 0.0001);

        // Drawer should hold 100.00 opening + (100.00 tendered - 44.50 change)
        // = 155.50. The cashier counts 100 + 50 + 5 = 155.00, so it is 0.50 short.
        $this->actingAs($cashier)->post('/shifts/close', [
            'closing_cash_usd' => 0,   // deliberately wrong: the count must win
            'closing_cash_lbp' => 0,
            'denominations' => [
                'usd' => [100 => 1, 50 => 1, 5 => 1],
                'lbp' => [],
            ],
            'notes' => 'End of day',
        ])->assertRedirect('/shifts/open');

        $shift->refresh();

        $this->assertSame(Shift::STATUS_CLOSED, $shift->status);
        $this->assertEqualsWithDelta(155.00, (float) $shift->closing_cash_usd, 0.0001);
        $this->assertEqualsWithDelta(155.50, (float) $shift->expected_cash_usd, 0.0001);
        $this->assertEqualsWithDelta(-0.50, (float) $shift->variance_usd, 0.0001);
        $this->assertSame(['100' => 1, '50' => 1, '5' => 1], $shift->closing_denominations['usd']);
        $this->assertNotNull($shift->closed_at);
        $this->assertSame($cashier->id, $shift->closed_by);
    }

    public function test_lbp_change_leaves_the_drawer_and_shows_up_in_expected_lbp(): void
    {
        $cashier = $this->cashier();
        $shift = $this->openShift($cashier, usd: 0, lbp: 10_000_000);

        $vat = Tax::create([
            'name' => 'Lebanese VAT', 'rate' => 0.11, 'is_inclusive' => false,
            'is_default' => true, 'is_active' => true,
        ]);
        \App\Models\Setting::set('exchange_rate', 90000, 'currency', 'float');
        \App\Models\Setting::set('lbp_rounding_step', 1000, 'currency', 'int');

        $product = Product::factory()->create([
            'price_usd' => 10.00, 'cost_usd' => 4.00, 'stock_qty' => 10,
            'tax_id' => $vat->id, 'is_taxable' => true,
        ]);

        // 10.00 + 1.10 VAT = 11.10 due. Tender $20 cash, take all change in LBP:
        // change 8.90 USD -> 801,000 LBP (8.90 * 90,000 = 801,000, on the step).
        $sale = Sale::findOrFail(
            $this->actingAs($cashier)->postJson('/pos/api/sales', [
                'cart' => [['product_id' => $product->id, 'qty' => 1, 'unit_price' => 10.00]],
                'payment' => ['method' => 'cash_usd', 'amount_usd' => 20.00, 'change_usd_out' => 0],
            ])->assertCreated()->json('sale.id')
        );

        $this->assertEqualsWithDelta(0.0, (float) $sale->change_usd, 0.0001);
        $this->assertEqualsWithDelta(801_000, (float) $sale->change_lbp, 0.5);

        $this->actingAs($cashier)->post('/shifts/close', [
            'closing_cash_usd' => 20,
            'closing_cash_lbp' => 9_199_000,
        ])->assertRedirect('/shifts/open');

        $shift->refresh();

        // USD in: 20.00 tendered, nothing back. LBP out: 801,000 of change.
        $this->assertEqualsWithDelta(20.00, (float) $shift->expected_cash_usd, 0.0001);
        $this->assertEqualsWithDelta(10_000_000 - 801_000, (float) $shift->expected_cash_lbp, 0.5);
        $this->assertEqualsWithDelta(0.0, (float) $shift->variance_lbp, 0.5);
    }

    public function test_typed_totals_are_used_when_no_denominations_are_counted(): void
    {
        $cashier = $this->cashier();
        $shift = $this->openShift($cashier, usd: 40);

        $this->actingAs($cashier)->post('/shifts/close', [
            'closing_cash_usd' => 37.25,
            'closing_cash_lbp' => 0,
        ])->assertRedirect('/shifts/open');

        $shift->refresh();

        $this->assertEqualsWithDelta(37.25, (float) $shift->closing_cash_usd, 0.0001);
        $this->assertEqualsWithDelta(40.00, (float) $shift->expected_cash_usd, 0.0001);
        $this->assertEqualsWithDelta(-2.75, (float) $shift->variance_usd, 0.0001);
        $this->assertNull($shift->closing_denominations);
    }

    public function test_a_closed_shift_blocks_further_sales_until_a_new_one_is_opened(): void
    {
        $cashier = $this->cashier();
        $this->openShift($cashier);

        $product = Product::factory()->create(['price_usd' => 5.00, 'stock_qty' => 10, 'is_taxable' => false]);

        $this->actingAs($cashier)->post('/shifts/close', [
            'closing_cash_usd' => 100, 'closing_cash_lbp' => 0,
        ])->assertRedirect('/shifts/open');

        // The POS screen bounces to the open-shift page...
        $this->actingAs($cashier)->get('/pos')->assertRedirect('/shifts/open');

        // ...and the sale endpoint refuses outright.
        $this->actingAs($cashier)->postJson('/pos/api/sales', [
            'cart' => [['product_id' => $product->id, 'qty' => 1, 'unit_price' => 5.00]],
            'payment' => ['method' => 'cash_usd', 'amount_usd' => 5.00],
        ])->assertStatus(409);

        $this->assertSame(0, Sale::count());

        // Opening a fresh shift restores selling.
        $this->actingAs($cashier)->post('/shifts/open', [
            'opening_cash_usd' => 50, 'opening_cash_lbp' => 0,
        ])->assertRedirect('/pos');

        $this->actingAs($cashier)->postJson('/pos/api/sales', [
            'cart' => [['product_id' => $product->id, 'qty' => 1, 'unit_price' => 5.00]],
            'payment' => ['method' => 'cash_usd', 'amount_usd' => 5.00],
        ])->assertCreated();
    }

    public function test_close_page_shows_the_expected_drawer_total(): void
    {
        $cashier = $this->cashier();
        $this->openShift($cashier, usd: 100);
        $this->sellFiftyFive($cashier);

        $this->actingAs($cashier)->get('/shifts/close')
            ->assertOk()
            ->assertSee('Expected cash (USD)')
            ->assertSee('155.50');
    }

    public function test_closing_without_an_open_shift_is_a_no_op(): void
    {
        $cashier = $this->cashier();

        $this->actingAs($cashier)->get('/shifts/close')->assertRedirect('/shifts/open');
        $this->actingAs($cashier)->post('/shifts/close', [
            'closing_cash_usd' => 10, 'closing_cash_lbp' => 0,
        ])->assertRedirect('/');

        $this->assertSame(0, Shift::count());
    }
}
