<?php

namespace Tests\Feature\BugHunt;

use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Shift;
use App\Models\User;
use App\Services\ShiftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BUG-033 (follow-up to BUG-021): since BUG-021 a `partial_refund` sale counts in the shift's expected
 * cash in full, but the cash handed back for the refund is still not subtracted. A $100 cash sale of
 * 5 x $20 with 4 units refunded in cash ($80) leaves $20 in the drawer; the shift expects $100.
 *
 * Covers the SAME-shift case: sale and cash refund both happen while this shift is open, so the
 * drawer arithmetic is unambiguous whether the fix nets the refund against the sale or records it as a
 * cash-out movement. (A refund paid in a LATER shift is a business question, see BUG-028.)
 */
class Bug033PartialCashRefundIsNotDeductedFromShiftExpectedCashTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0:User,1:Shift,2:Sale,3:SaleItem} */
    private function cashSaleOfFiveTwentyDollarUnits(): array
    {
        $manager = User::factory()->manager()->create();
        $shift = Shift::create([
            'user_id' => $manager->id, 'opened_at' => now(),
            'opening_cash_usd' => 0, 'opening_cash_lbp' => 0, 'status' => Shift::STATUS_OPEN,
        ]);
        $product = Product::factory()->create(['stock_qty' => 10, 'track_stock' => true, 'price_usd' => 20]);
        $sale = Sale::create([
            'receipt_number' => 'R-0500', 'user_id' => $manager->id, 'shift_id' => $shift->id,
            'subtotal_usd' => 100, 'total_usd' => 100, 'exchange_rate' => 90000,
            'payment_method' => 'cash_usd', 'amount_tendered_usd' => 100, 'change_usd' => 0,
            'status' => Sale::STATUS_COMPLETED,
        ]);
        $line = SaleItem::create([
            'sale_id' => $sale->id, 'product_id' => $product->id, 'product_name' => $product->name,
            'qty' => 5, 'unit_price_usd' => 20, 'line_total_usd' => 100,
        ]);

        return [$manager, $shift, $sale, $line];
    }

    public function test_a_cash_refund_of_part_of_a_cash_sale_leaves_only_the_remaining_cash_expected_in_the_drawer(): void
    {
        [$manager, $shift, $sale, $line] = $this->cashSaleOfFiveTwentyDollarUnits();

        // The manager returns 4 of the 5 units and hands $80 back in cash (auto-approved for a manager).
        $this->actingAs($manager)->post(route('returns.store', $sale), [
            'items' => [['sale_item_id' => $line->id, 'qty_returned' => 4, 'restock' => 1]],
            'reason' => 'defective', 'refund_method' => 'cash_usd',
        ])->assertSessionHasNoErrors();

        $this->assertSame(Sale::STATUS_PARTIAL_REFUND, $sale->fresh()->status, 'Precondition: the sale is partially refunded.');

        $totals = app(ShiftService::class)->totals($shift);

        // $100 taken, $80 handed back: the drawer physically holds $20.
        $this->assertEquals(20.0, $totals['expected_usd'], "The shift expects \${$totals['expected_usd']} in the drawer although \$100 came in and \$80 went back out as a cash refund.");
    }

    public function test_a_refund_to_the_customers_account_does_not_reduce_expected_cash(): void
    {
        // Guard against over-fixing: only cash that leaves the drawer is deducted. This passes today.
        [$manager, $shift, $sale, $line] = $this->cashSaleOfFiveTwentyDollarUnits();
        $sale->update(['customer_id' => \App\Models\Customer::create(['name' => 'Rami', 'customer_group' => 'retail', 'is_active' => true, 'credit_limit' => 0])->id]);

        $this->actingAs($manager)->post(route('returns.store', $sale), [
            'items' => [['sale_item_id' => $line->id, 'qty_returned' => 4, 'restock' => 1]],
            'reason' => 'defective', 'refund_method' => 'account',
        ])->assertSessionHasNoErrors();

        $this->assertEquals(100.0, app(ShiftService::class)->totals($shift)['expected_usd']);
    }
}
