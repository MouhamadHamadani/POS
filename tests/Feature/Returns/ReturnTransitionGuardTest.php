<?php

namespace Tests\Feature\Returns;

use App\Models\Customer;
use App\Models\Product;
use App\Models\ReturnTransaction;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BUG-036: pending -> approved / rejected is one conditional UPDATE, so a return that already left
 * `pending` (in the database, whatever the request still holds in memory) is neither applied nor
 * re-opened by a second approve or reject.
 */
class ReturnTransitionGuardTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0:User,1:ReturnTransaction,2:SaleItem,3:Product,4:Customer} a pending account return of 2 of 5 units ($40) */
    private function pendingReturn(): array
    {
        $manager = User::factory()->manager()->create();
        $cashier = User::factory()->create();
        $shift = Shift::create([
            'user_id' => $manager->id, 'opened_at' => now(),
            'opening_cash_usd' => 0, 'opening_cash_lbp' => 0, 'status' => Shift::STATUS_OPEN,
        ]);
        $customer = Customer::create(['name' => 'Rami', 'customer_group' => 'retail', 'is_active' => true, 'credit_limit' => 0, 'balance' => 0]);
        $product = Product::factory()->create(['stock_qty' => 10, 'track_stock' => true]);
        $sale = Sale::create([
            'receipt_number' => 'R-0710', 'user_id' => $cashier->id, 'shift_id' => $shift->id, 'customer_id' => $customer->id,
            'subtotal_usd' => 100, 'total_usd' => 100, 'exchange_rate' => 90000,
            'payment_method' => 'cash_usd', 'status' => Sale::STATUS_COMPLETED,
        ]);
        $line = SaleItem::create([
            'sale_id' => $sale->id, 'product_id' => $product->id, 'product_name' => $product->name,
            'qty' => 5, 'unit_price_usd' => 20, 'line_total_usd' => 100,
        ]);
        $this->actingAs($cashier)->post(route('returns.store', $sale), [
            'items' => [['sale_item_id' => $line->id, 'qty_returned' => 2, 'restock' => 1]],
            'reason' => 'defective', 'refund_method' => 'account',
        ])->assertSessionHasNoErrors();

        return [$manager, ReturnTransaction::firstOrFail(), $line, $product, $customer];
    }

    public function test_approving_a_return_that_is_no_longer_pending_changes_nothing_and_says_so(): void
    {
        [$manager, $return, $line, $product, $customer] = $this->pendingReturn();
        $this->actingAs($manager)->post(route('returns.approve', $return))->assertSessionHasNoErrors();

        $this->actingAs($manager)->post(route('returns.approve', $return))->assertSessionHasErrors('status');

        $this->assertEquals(2.0, (float) $line->fresh()->returned_qty);
        $this->assertEquals(12.0, (float) $product->fresh()->stock_qty);
        $this->assertEquals(-40.0, (float) $customer->fresh()->balance);
        $this->assertSame(1, \App\Models\AuditLog::where('action', 'return_approve')->count());
    }

    public function test_approving_a_return_that_was_rejected_in_the_meantime_applies_nothing(): void
    {
        [$manager, $return, $line, $product, $customer] = $this->pendingReturn();

        $this->actingAs($manager)->post(route('returns.reject', $return))->assertSessionHasNoErrors();
        $this->actingAs($manager)->post(route('returns.approve', $return))->assertSessionHasErrors('status');

        $this->assertSame(ReturnTransaction::STATUS_REJECTED, $return->fresh()->status);
        $this->assertEquals(0.0, (float) $line->fresh()->returned_qty);
        $this->assertEquals(10.0, (float) $product->fresh()->stock_qty);
        $this->assertEquals(0.0, (float) $customer->fresh()->balance);
    }

    public function test_rejecting_a_return_that_was_already_applied_does_not_reopen_it(): void
    {
        [$manager, $return, $line] = $this->pendingReturn();
        $this->actingAs($manager)->post(route('returns.approve', $return))->assertSessionHasNoErrors();

        $this->actingAs($manager)->post(route('returns.reject', $return))->assertSessionHasErrors('status');

        $this->assertSame(ReturnTransaction::STATUS_COMPLETED, $return->fresh()->status);
        $this->assertSame(0, \App\Models\AuditLog::where('action', 'return_reject')->count());
        $this->assertEquals(2.0, (float) $line->fresh()->returned_qty);
    }
}
