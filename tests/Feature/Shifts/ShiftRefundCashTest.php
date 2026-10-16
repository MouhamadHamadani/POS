<?php

namespace Tests\Feature\Shifts;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Shift;
use App\Models\User;
use App\Services\ShiftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BUG-033: the drawer holds the cash of the shift's sales less the cash refunds paid back for them.
 * A refund is netted against the shift that rang the sale, whatever the sale's status afterwards,
 * so a fully refunded sale is never subtracted twice.
 */
class ShiftRefundCashTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0:User,1:Shift,2:Sale,3:SaleItem} A $100 cash sale of 5 x $20 in a shift opened with $50. */
    private function cashSale(): array
    {
        $manager = User::factory()->manager()->create();
        $shift = Shift::create([
            'user_id' => $manager->id, 'opened_at' => now(),
            'opening_cash_usd' => 50, 'opening_cash_lbp' => 5_000_000, 'status' => Shift::STATUS_OPEN,
        ]);
        $product = Product::factory()->create(['stock_qty' => 10, 'track_stock' => true, 'price_usd' => 20]);
        $customer = Customer::create(['name' => 'Rami', 'customer_group' => 'retail', 'is_active' => true, 'credit_limit' => 0]);
        $sale = Sale::create([
            'receipt_number' => 'R-0600', 'user_id' => $manager->id, 'shift_id' => $shift->id, 'customer_id' => $customer->id,
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

    private function refund(User $by, Sale $sale, SaleItem $line, float $qty, string $method): void
    {
        $this->actingAs($by)->post(route('returns.store', $sale), [
            'items' => [['sale_item_id' => $line->id, 'qty_returned' => $qty, 'restock' => 1]],
            'reason' => 'defective', 'refund_method' => $method,
        ])->assertSessionHasNoErrors();
    }

    private function totals(Shift $shift): array
    {
        return app(ShiftService::class)->totals($shift->fresh());
    }

    public function test_a_fully_cash_refunded_sale_in_the_same_shift_nets_to_nothing_and_is_not_subtracted_twice(): void
    {
        [$manager, $shift, $sale, $line] = $this->cashSale();
        $this->refund($manager, $sale, $line, 5, 'cash_usd');

        $t = $this->totals($shift);

        $this->assertSame(Sale::STATUS_REFUNDED, $sale->fresh()->status);
        $this->assertEquals(50.0, $t['expected_usd'], 'Opening $50 only: $100 came in and $100 went back out.');
        $this->assertSame(0, $t['sales_count'], 'A fully refunded sale is still not a sale of the shift.');
        $this->assertEquals(0.0, $t['total_revenue_usd']);
    }

    public function test_a_fully_refunded_sale_refunded_to_the_account_keeps_its_cash_in_the_drawer(): void
    {
        [$manager, $shift, $sale, $line] = $this->cashSale();
        $this->refund($manager, $sale, $line, 5, 'account');

        $t = $this->totals($shift);

        $this->assertSame(Sale::STATUS_REFUNDED, $sale->fresh()->status);
        $this->assertEquals(150.0, $t['expected_usd'], 'The customer was credited, not paid: the $100 is still in the drawer.');
        $this->assertSame(0, $t['sales_count']);
    }

    public function test_a_sale_refunded_part_to_the_account_and_the_rest_in_cash_nets_only_the_cash(): void
    {
        [$manager, $shift, $sale, $line] = $this->cashSale();
        $this->refund($manager, $sale, $line, 4, 'account');
        $this->refund($manager, $sale, $line, 1, 'cash_usd');

        $this->assertSame(Sale::STATUS_REFUNDED, $sale->fresh()->status);
        $this->assertEquals(130.0, $this->totals($shift)['expected_usd']);
    }

    public function test_a_partial_cash_refund_leaves_a_zero_variance_on_a_correct_count(): void
    {
        [$manager, $shift, $sale, $line] = $this->cashSale();
        $this->refund($manager, $sale, $line, 4, 'cash_usd');

        // $50 opening + $100 sale - $80 refund = $70 physically in the drawer.
        $this->actingAs($manager)->post('/shifts/close', [
            'closing_cash_usd' => 70, 'closing_cash_lbp' => 5_000_000,
        ])->assertRedirect('/shifts/open');

        $shift->refresh();
        $this->assertEquals(70.0, (float) $shift->expected_cash_usd);
        $this->assertEquals(0.0, (float) $shift->variance_usd);
        $this->assertEquals(0.0, (float) $shift->variance_lbp);
        $this->assertSame(1, $this->totals($shift)['sales_count'], 'The partly refunded sale still counts as a sale.');
    }

    public function test_a_cash_lbp_refund_comes_out_of_the_lbp_drawer_not_the_usd_one(): void
    {
        [$manager, $shift, $sale, $line] = $this->cashSale();
        $this->refund($manager, $sale, $line, 2, 'cash_lbp');

        $paidLbp = (float) $sale->returns()->first()->refund_amount_lbp;
        $this->assertGreaterThan(0, $paidLbp);

        $t = $this->totals($shift);
        $this->assertEquals(150.0, $t['expected_usd'], 'Paid in LBP notes: the dollars are untouched.');
        $this->assertEquals(5_000_000 - $paidLbp, $t['expected_lbp']);
    }

    public function test_a_pending_return_moves_no_cash_until_it_is_approved(): void
    {
        [$manager, $shift, $sale, $line] = $this->cashSale();
        $cashier = User::factory()->create(['role' => User::ROLE_CASHIER]);
        $this->refund($cashier, $sale, $line, 4, 'cash_usd');

        $this->assertEquals(150.0, $this->totals($shift)['expected_usd']);

        $this->actingAs($manager)->post(route('returns.approve', $sale->returns()->first()))->assertSessionHasNoErrors();

        $this->assertEquals(70.0, $this->totals($shift)['expected_usd']);
    }
}
