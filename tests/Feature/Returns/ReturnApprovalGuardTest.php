<?php

namespace Tests\Feature\Returns;

use App\Models\Product;
use App\Models\ReturnTransaction;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Approving a return re-checks the line inside the approving transaction (BUG-011), so two
 * pending returns that already exist for the same units (made before store() counted pending
 * returns) cannot both be applied.
 */
class ReturnApprovalGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_second_pending_return_for_the_same_units_is_refused_on_approval(): void
    {
        $manager = User::factory()->manager()->create();
        $shift = Shift::create([
            'user_id' => $manager->id, 'opened_at' => now(),
            'opening_cash_usd' => 0, 'opening_cash_lbp' => 0, 'status' => Shift::STATUS_OPEN,
        ]);
        $product = Product::factory()->create(['stock_qty' => 10, 'track_stock' => true]);
        $sale = Sale::create([
            'receipt_number' => 'R-0900', 'user_id' => $manager->id, 'shift_id' => $shift->id,
            'subtotal_usd' => 20, 'total_usd' => 20, 'exchange_rate' => 90000,
            'payment_method' => 'cash_usd', 'status' => Sale::STATUS_COMPLETED,
        ]);
        $line = SaleItem::create([
            'sale_id' => $sale->id, 'product_id' => $product->id, 'product_name' => $product->name,
            'qty' => 2, 'unit_price_usd' => 10, 'line_total_usd' => 20,
        ]);

        $pending = [];
        foreach (['RTN-A', 'RTN-B'] as $n) {
            $rt = ReturnTransaction::create([
                'return_number' => $n, 'sale_id' => $sale->id, 'user_id' => $manager->id,
                'reason' => 'defective', 'refund_method' => 'cash_usd',
                'refund_amount_usd' => 20, 'status' => ReturnTransaction::STATUS_PENDING,
            ]);
            $rt->items()->create([
                'sale_item_id' => $line->id, 'product_id' => $product->id,
                'qty_returned' => 2, 'refund_amount_usd' => 20, 'restock' => true, 'condition' => 'good',
            ]);
            $pending[] = $rt;
        }

        $this->actingAs($manager)->post(route('returns.approve', $pending[0]))->assertSessionHasNoErrors();
        $this->actingAs($manager)->post(route('returns.approve', $pending[1]))->assertSessionHasErrors('items');

        $this->assertEquals(2, (float) $line->fresh()->returned_qty);
        $this->assertEquals(12, (float) $product->fresh()->stock_qty);
        $this->assertSame(ReturnTransaction::STATUS_COMPLETED, $pending[0]->fresh()->status);
        $this->assertSame(ReturnTransaction::STATUS_PENDING, $pending[1]->fresh()->status);
    }
}
