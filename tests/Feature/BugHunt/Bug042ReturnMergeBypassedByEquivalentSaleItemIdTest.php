<?php

namespace Tests\Feature\BugHunt;

use App\Models\Customer;
use App\Models\Product;
use App\Models\ReturnItem;
use App\Models\ReturnTransaction;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Shift;
use App\Models\User;
use App\Services\ShiftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * BUG-042 (hole left by the BUG-035 fix): ReturnController::store() merges duplicate lines with
 * groupBy('sale_item_id') on the RAW posted value. The `integer` rule accepts "+5" (filter_var), TrimStrings does
 * not strip a plus sign, and SaleItem::where('id', '+5') matches row 5 on SQLite. So "5" and "+5" become two
 * groups, each passes returnableQty() on its own (nothing is persisted until the end), $refundAmount is added twice
 * while $perLine (keyed on the real id) is overwritten: the header refunds $200 for a $100 line whose single item
 * row says $100. Cash: the cashier is told to hand over $200 and the shift expects -$100. Account: the customer is
 * credited $200. A cashier can file it pending.
 */
class Bug042ReturnMergeBypassedByEquivalentSaleItemIdTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string,array{0:string,1:string,2:string}> spelling of the 2nd entry's id (%d = the id), who files it, refund method */
    public static function payloads(): array
    {
        return [
            'plus sign, manager, cash' => ['+%d', 'manager', 'cash_usd'],
            'plus sign, manager, account' => ['+%d', 'manager', 'account'],
            'plus sign, cashier files it pending' => ['+%d', 'cashier', 'cash_usd'],
            // Guard: the plain duplicate is what BUG-035 fixed; it must stay fixed.
            'plain duplicate, manager, cash' => ['%d', 'manager', 'cash_usd'],
        ];
    }

    #[DataProvider('payloads')]
    public function test_a_line_posted_twice_under_equivalent_ids_is_refunded_once(string $spelling, string $who, string $method): void
    {
        $manager = User::factory()->manager()->create();
        $filer = $who === 'manager' ? $manager : User::factory()->create();
        $shift = Shift::create([
            'user_id' => $manager->id, 'opened_at' => now(),
            'opening_cash_usd' => 0, 'opening_cash_lbp' => 0, 'status' => Shift::STATUS_OPEN,
        ]);
        $customer = Customer::create(['name' => 'Rami', 'customer_group' => 'retail', 'is_active' => true, 'credit_limit' => 0, 'balance' => 0]);
        $product = Product::factory()->create(['stock_qty' => 10, 'track_stock' => true]);
        $sale = Sale::create([
            'receipt_number' => 'R-0900', 'user_id' => $manager->id, 'shift_id' => $shift->id, 'customer_id' => $customer->id,
            'subtotal_usd' => 100, 'total_usd' => 100, 'exchange_rate' => 90000,
            'payment_method' => 'cash_usd', 'amount_tendered_usd' => 100, 'change_usd' => 0,
            'status' => Sale::STATUS_COMPLETED,
        ]);
        $line = SaleItem::create([
            'sale_id' => $sale->id, 'product_id' => $product->id, 'product_name' => $product->name,
            'qty' => 5, 'unit_price_usd' => 20, 'line_total_usd' => 100,
        ]);

        // Exactly what a browser / API client sends: the line twice, the second time spelled "+<id>".
        $this->actingAs($filer)->post(route('returns.store', $sale), [
            'items' => [
                ['sale_item_id' => (string) $line->id, 'qty_returned' => 5, 'restock' => 1],
                ['sale_item_id' => sprintf($spelling, $line->id), 'qty_returned' => 5, 'restock' => 1],
            ],
            'reason' => 'other', 'refund_method' => $method,
        ]);

        // Whatever the fix does (merge on the integer id, or refuse the request), these must hold.
        $header = (float) ReturnTransaction::sum('refund_amount_usd');
        $itemRows = (float) ReturnItem::sum('refund_amount_usd');

        $this->assertLessThanOrEqual(100.0, $header, "The return header refunds \${$header} for a line that sold for \$100.");
        $this->assertEqualsWithDelta($itemRows, $header, 0.0001, "The return header refunds \${$header} but its item rows add up to \${$itemRows}.");

        // The money side effects of the (auto-approved) manager return follow the header.
        $this->assertGreaterThanOrEqual(-100.0, (float) $customer->fresh()->balance, 'The customer was credited more than the line cost.');
        $this->assertGreaterThanOrEqual(
            0.0,
            app(ShiftService::class)->totals($shift->fresh())['expected_usd'],
            'The shift expects negative cash after handing back more than the $100 the sale brought in.',
        );
        $this->assertLessThanOrEqual(5.0, (float) $line->fresh()->returned_qty);
        $this->assertLessThanOrEqual(15.0, (float) $product->fresh()->stock_qty);
    }
}
