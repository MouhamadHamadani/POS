<?php

namespace Tests\Feature\BugHunt;

use App\Http\Controllers\ReturnController;
use App\Models\Product;
use App\Models\ReturnTransaction;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * BUG-036: ReturnController::approve() reads the return's status BEFORE its transaction and the status update is
 * unconditional, so two approvals that both loaded the return while it was still `pending` (a double click on
 * "Approve refund", or two managers) both run processApproval(). BUG-011's guard only stops returned_qty
 * from passing the quantity sold, so when the line has spare quantity the restock and the account credit apply
 * twice.
 *
 * A single-process test cannot interleave two real requests, so this hands the controller two model instances
 * that both hold the stale `pending` state, exactly what two overlapping requests would have in memory.
 */
class Bug036ReturnCanBeApprovedTwiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_return_that_was_already_approved_is_not_applied_a_second_time(): void
    {
        $manager = User::factory()->manager()->create();
        $cashier = User::factory()->create();
        $shift = Shift::create([
            'user_id' => $manager->id, 'opened_at' => now(),
            'opening_cash_usd' => 0, 'opening_cash_lbp' => 0, 'status' => Shift::STATUS_OPEN,
        ]);
        $product = Product::factory()->create(['stock_qty' => 10, 'track_stock' => true]);
        $sale = Sale::create([
            'receipt_number' => 'R-0700', 'user_id' => $cashier->id, 'shift_id' => $shift->id,
            'subtotal_usd' => 100, 'total_usd' => 100, 'exchange_rate' => 90000,
            'payment_method' => 'cash_usd', 'status' => Sale::STATUS_COMPLETED,
        ]);
        $line = SaleItem::create([
            'sale_id' => $sale->id, 'product_id' => $product->id, 'product_name' => $product->name,
            'qty' => 5, 'unit_price_usd' => 20, 'line_total_usd' => 100,
        ]);

        // The cashier files a pending return of 2 of the 5 units.
        $this->actingAs($cashier)->post(route('returns.store', $sale), [
            'items' => [['sale_item_id' => $line->id, 'qty_returned' => 2, 'restock' => 1]],
            'reason' => 'defective', 'refund_method' => 'cash_usd',
        ])->assertSessionHasNoErrors();
        $return = ReturnTransaction::firstOrFail();
        $this->assertSame(ReturnTransaction::STATUS_PENDING, $return->status, 'Precondition: the return is pending.');

        // Two approvals, each having loaded the return while it was pending.
        $first = ReturnTransaction::findOrFail($return->id);
        $second = ReturnTransaction::findOrFail($return->id);

        $request = Request::create(route('returns.approve', $return), 'POST');
        $request->setUserResolver(fn () => $manager);
        $controller = app(ReturnController::class);

        $controller->approve($request, $first);
        try {
            $controller->approve($request, $second); // must be refused (redirect with an error, 4xx or validation error)
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException|\Illuminate\Validation\ValidationException) {
            // a refusal by exception is as good as a redirect
        }

        $this->assertEquals(2.0, (float) $line->fresh()->returned_qty, 'The two units were marked returned more than once.');
        $this->assertEquals(12.0, (float) $product->fresh()->stock_qty, 'The two returned units were restocked more than once.');
        $this->assertSame(ReturnTransaction::STATUS_COMPLETED, $return->fresh()->status);
    }
}
