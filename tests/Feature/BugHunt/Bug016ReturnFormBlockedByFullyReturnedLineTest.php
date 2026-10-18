<?php

namespace Tests\Feature\BugHunt;

use App\Models\Product;
use App\Models\ReturnTransaction;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\BugHunt\Concerns\SubmitsRenderedForms;
use Tests\TestCase;

/**
 * BUG-016: once one line of a sale has been fully returned, no further return can be filed for the sale.
 *
 * returns/create.blade.php disables the quantity input of a line with nothing left to return
 * (`:disabled="true"`), so the browser does not send `items[N][qty_returned]` for it. It still
 * sends that line's hidden `sale_item_id` (and the ticked Restock box), and
 * ReturnController::store() requires `items.*.qty_returned` on every submitted line, so the
 * request is rejected with "The items.1.qty_returned field is required." and the customer's
 * other item cannot be returned through the UI.
 */
class Bug016ReturnFormBlockedByFullyReturnedLineTest extends TestCase
{
    use RefreshDatabase;
    use SubmitsRenderedForms;

    public function test_returning_the_remaining_line_works_when_another_line_was_already_fully_returned(): void
    {
        $manager = User::factory()->manager()->create();
        $shift = Shift::create([
            'user_id' => $manager->id, 'opened_at' => now(),
            'opening_cash_usd' => 0, 'opening_cash_lbp' => 0, 'status' => Shift::STATUS_OPEN,
        ]);
        $a = Product::factory()->create(['stock_qty' => 10]);
        $b = Product::factory()->create(['stock_qty' => 10]);
        $sale = Sale::create([
            'receipt_number' => 'R-0003', 'user_id' => $manager->id, 'shift_id' => $shift->id,
            'subtotal_usd' => 30, 'total_usd' => 30, 'exchange_rate' => 90000,
            'payment_method' => 'cash_usd', 'status' => Sale::STATUS_PARTIAL_REFUND,
        ]);
        // Line 1: already returned in full by an earlier return.
        SaleItem::create([
            'sale_id' => $sale->id, 'product_id' => $a->id, 'product_name' => $a->name,
            'qty' => 1, 'unit_price_usd' => 10, 'line_total_usd' => 10, 'returned_qty' => 1, 'is_returned' => true,
        ]);
        // Line 2: untouched.
        SaleItem::create([
            'sale_id' => $sale->id, 'product_id' => $b->id, 'product_name' => $b->name,
            'qty' => 2, 'unit_price_usd' => 10, 'line_total_usd' => 20,
        ]);

        $html = $this->actingAs($manager)->get(route('returns.create', $sale))->assertOk()->getContent();

        $response = $this->submitRenderedForm($html, route('returns.store', $sale), set: [
            'items[1][qty_returned]' => '1',
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertSame(1, ReturnTransaction::count(), 'No return was created for the line that still had units to return.');
    }
}
