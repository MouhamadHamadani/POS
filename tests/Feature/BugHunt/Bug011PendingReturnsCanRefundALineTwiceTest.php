<?php

namespace Tests\Feature\BugHunt;

use App\Models\Product;
use App\Models\ReturnTransaction;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BUG-011: two pending returns for the same sale line can both be approved, refunding it twice.
 *
 * A cashier's return is created `pending`. ReturnController::store() checks the quantity
 * against `qty - returned_qty`, but returned_qty only moves when a manager approves, so a
 * second pending return for the same units passes the same check. approve() then applies
 * each one without re-checking: returned_qty ends above the quantity sold, the customer is
 * refunded twice, and the units are restocked twice (stock the shop never had).
 */
class Bug011PendingReturnsCanRefundALineTwiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_sale_line_cannot_be_returned_for_more_than_was_sold_across_pending_returns(): void
    {
        $manager = User::factory()->manager()->create();
        $cashier = User::factory()->create();
        $shift = Shift::create([
            'user_id' => $manager->id, 'opened_at' => now(),
            'opening_cash_usd' => 0, 'opening_cash_lbp' => 0, 'status' => Shift::STATUS_OPEN,
        ]);
        $product = Product::factory()->create(['stock_qty' => 10, 'track_stock' => true]);
        $sale = Sale::create([
            'receipt_number' => 'R-0002', 'user_id' => $manager->id, 'shift_id' => $shift->id,
            'subtotal_usd' => 20, 'total_usd' => 20, 'exchange_rate' => 90000,
            'payment_method' => 'cash_usd', 'status' => Sale::STATUS_COMPLETED,
        ]);
        $line = SaleItem::create([
            'sale_id' => $sale->id, 'product_id' => $product->id, 'product_name' => $product->name,
            'qty' => 2, 'unit_price_usd' => 10, 'line_total_usd' => 20,
        ]);

        $payload = [
            'items' => [['sale_item_id' => $line->id, 'qty_returned' => 2, 'restock' => 1]],
            'reason' => 'defective', 'refund_method' => 'cash_usd',
        ];

        // The cashier files the same two units twice (a double click, or two tills).
        $this->actingAs($cashier)->post(route('returns.store', $sale), $payload);
        $this->actingAs($cashier)->post(route('returns.store', $sale), $payload);

        foreach (ReturnTransaction::where('status', ReturnTransaction::STATUS_PENDING)->get() as $pending) {
            $this->actingAs($manager)->post(route('returns.approve', $pending));
        }

        $returned = (float) $line->fresh()->returned_qty;
        $refunded = (float) ReturnTransaction::where('status', ReturnTransaction::STATUS_COMPLETED)->sum('refund_amount_usd');

        $this->assertLessThanOrEqual(2.0, $returned, "{$returned} units are marked returned on a line that sold 2.");
        $this->assertLessThanOrEqual(20.0, $refunded, "\${$refunded} was refunded on a \$20 line.");
        $this->assertLessThanOrEqual(12.0, (float) $product->fresh()->stock_qty, 'Returned units were restocked more than once.');
    }
}
