<?php

namespace Tests\Feature\BugHunt;

use App\Models\Customer;
use App\Models\Product;
use App\Models\ReturnTransaction;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * BUG-035: ReturnController::store() validates each posted entry against the line's returnable quantity on
 * its own, keeps only the LAST entry per sale_item_id in $perLine, but adds EVERY entry to $refundAmount.
 * Posting the same sale_item_id twice therefore stores a return header refunding (and, for account refunds,
 * crediting the customer) twice what the single item row says, for units that were sold once.
 * A cashier can file the pending return with such a payload; a manager's is auto-approved.
 */
class Bug035ReturnWithSameSaleLineTwiceDoublesRefundTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string,array{0:float,1:float}> qty of entry 1, qty of entry 2 (line sold 5 at $20) */
    public static function duplicates(): array
    {
        return [
            'the whole line twice' => [5.0, 5.0],
            'two partial entries adding up past the line' => [3.0, 3.0],
        ];
    }

    #[DataProvider('duplicates')]
    public function test_posting_the_same_sale_line_twice_never_refunds_more_than_the_line_or_double_counts(float $first, float $second): void
    {
        $manager = User::factory()->manager()->create();
        $shift = Shift::create([
            'user_id' => $manager->id, 'opened_at' => now(),
            'opening_cash_usd' => 0, 'opening_cash_lbp' => 0, 'status' => Shift::STATUS_OPEN,
        ]);
        $customer = Customer::create(['name' => 'Rami', 'customer_group' => 'retail', 'is_active' => true, 'credit_limit' => 0, 'balance' => 0]);
        $product = Product::factory()->create(['stock_qty' => 10, 'track_stock' => true]);
        $sale = Sale::create([
            'receipt_number' => 'R-0600', 'user_id' => $manager->id, 'shift_id' => $shift->id, 'customer_id' => $customer->id,
            'subtotal_usd' => 100, 'total_usd' => 100, 'exchange_rate' => 90000,
            'payment_method' => 'cash_usd', 'status' => Sale::STATUS_COMPLETED,
        ]);
        $line = SaleItem::create([
            'sale_id' => $sale->id, 'product_id' => $product->id, 'product_name' => $product->name,
            'qty' => 5, 'unit_price_usd' => 20, 'line_total_usd' => 100,
        ]);

        // Same sale_item_id in two entries (a tampered or double-built payload; the form never sends this).
        $this->actingAs($manager)->post(route('returns.store', $sale), [
            'items' => [
                ['sale_item_id' => $line->id, 'qty_returned' => $first, 'restock' => 1],
                ['sale_item_id' => $line->id, 'qty_returned' => $second, 'restock' => 1],
            ],
            'reason' => 'other', 'refund_method' => 'account',
        ]);

        // Whatever the fix does (reject the request, or merge the entries and cap them), these must hold.
        $refunded = (float) ReturnTransaction::sum('refund_amount_usd');
        $itemRefunds = (float) \App\Models\ReturnItem::sum('refund_amount_usd');

        $this->assertLessThanOrEqual(100.0, $refunded, "The return header refunds \${$refunded} for a line that sold for \$100.");
        $this->assertEqualsWithDelta($itemRefunds, $refunded, 0.0001, "The header refunds \${$refunded} but its item rows add up to \${$itemRefunds}.");
        $this->assertGreaterThanOrEqual(-100.0, (float) $customer->fresh()->balance, 'The customer was credited more than the line cost.');
        $this->assertLessThanOrEqual(15.0, (float) $product->fresh()->stock_qty, 'More units were restocked than were sold.');
        $this->assertLessThanOrEqual(5.0, (float) $line->fresh()->returned_qty);
    }
}
