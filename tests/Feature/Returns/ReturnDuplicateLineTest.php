<?php

namespace Tests\Feature\Returns;

use App\Models\Customer;
use App\Models\Product;
use App\Models\ReturnItem;
use App\Models\ReturnTransaction;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BUG-035: a sale line posted twice in one return is merged (quantities summed) before it is checked
 * against what can still be returned, on the pending path and on the manager's auto-approve path.
 */
class ReturnDuplicateLineTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0:User,1:Sale,2:SaleItem,3:Product,4:Customer} A $100 sale, 5 x $20 */
    private function sale(): array
    {
        $manager = User::factory()->manager()->create();
        $shift = Shift::create([
            'user_id' => $manager->id, 'opened_at' => now(),
            'opening_cash_usd' => 0, 'opening_cash_lbp' => 0, 'status' => Shift::STATUS_OPEN,
        ]);
        $customer = Customer::create(['name' => 'Rami', 'customer_group' => 'retail', 'is_active' => true, 'credit_limit' => 0, 'balance' => 0]);
        $product = Product::factory()->create(['stock_qty' => 10, 'track_stock' => true]);
        $sale = Sale::create([
            'receipt_number' => 'R-0610', 'user_id' => $manager->id, 'shift_id' => $shift->id, 'customer_id' => $customer->id,
            'subtotal_usd' => 100, 'total_usd' => 100, 'exchange_rate' => 90000,
            'payment_method' => 'cash_usd', 'status' => Sale::STATUS_COMPLETED,
        ]);
        $line = SaleItem::create([
            'sale_id' => $sale->id, 'product_id' => $product->id, 'product_name' => $product->name,
            'qty' => 5, 'unit_price_usd' => 20, 'line_total_usd' => 100,
        ]);

        return [$manager, $sale, $line, $product, $customer];
    }

    private function postDuplicate(User $by, Sale $sale, SaleItem $line, float $a, float $b)
    {
        return $this->actingAs($by)->post(route('returns.store', $sale), [
            'items' => [
                ['sale_item_id' => $line->id, 'qty_returned' => $a, 'restock' => 1],
                ['sale_item_id' => $line->id, 'qty_returned' => $b, 'restock' => 1],
            ],
            'reason' => 'other', 'refund_method' => 'account',
        ]);
    }

    public function test_two_entries_for_a_line_that_add_up_to_what_is_left_become_one_item_refunded_once(): void
    {
        [$manager, $sale, $line, $product, $customer] = $this->sale();

        $this->postDuplicate($manager, $sale, $line, 3, 2)->assertSessionHasNoErrors();

        $this->assertSame(1, ReturnItem::count());
        $this->assertEquals(5.0, (float) ReturnItem::first()->qty_returned);
        $this->assertEquals(100.0, (float) ReturnTransaction::first()->refund_amount_usd);
        $this->assertEquals(100.0, (float) ReturnItem::first()->refund_amount_usd);
        $this->assertEquals(15.0, (float) $product->fresh()->stock_qty);
        $this->assertEquals(-100.0, (float) $customer->fresh()->balance);
    }

    public function test_a_cashiers_pending_return_with_a_duplicated_line_is_merged_and_applies_once_when_approved(): void
    {
        [$manager, $sale, $line, $product, $customer] = $this->sale();
        $cashier = User::factory()->create();

        $this->postDuplicate($cashier, $sale, $line, 1, 1)->assertSessionHasNoErrors();

        $return = ReturnTransaction::firstOrFail();
        $this->assertSame(ReturnTransaction::STATUS_PENDING, $return->status);
        $this->assertSame(1, $return->items()->count());
        $this->assertEquals(2.0, (float) $return->items()->first()->qty_returned);
        $this->assertEquals(40.0, (float) $return->refund_amount_usd);

        $this->actingAs($manager)->post(route('returns.approve', $return))->assertSessionHasNoErrors();

        $this->assertEquals(2.0, (float) $line->fresh()->returned_qty);
        $this->assertEquals(12.0, (float) $product->fresh()->stock_qty);
        $this->assertEquals(-40.0, (float) $customer->fresh()->balance);
    }

    public function test_entries_that_add_up_past_the_line_are_refused_and_nothing_is_created(): void
    {
        [$manager, $sale, $line, $product, $customer] = $this->sale();

        $this->postDuplicate($manager, $sale, $line, 3, 3)->assertSessionHasErrors('items');

        $this->assertSame(0, ReturnTransaction::count());
        $this->assertEquals(10.0, (float) $product->fresh()->stock_qty);
        $this->assertEquals(0.0, (float) $customer->fresh()->balance);
    }
}
