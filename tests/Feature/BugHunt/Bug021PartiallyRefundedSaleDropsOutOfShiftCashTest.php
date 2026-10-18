<?php

namespace Tests\Feature\BugHunt;

use App\Models\Sale;
use App\Models\Shift;
use App\Models\User;
use App\Services\ShiftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BUG-021: a sale that has been partly refunded vanishes from the shift's expected cash and revenue.
 *
 * ShiftService::totals() sums only sales whose status is exactly `completed`. A return of one
 * item flips the sale to `partial_refund` (ReturnController::processApproval), so the whole
 * sale — cash already in the drawer — stops counting. Selling $100 in cash and refunding $10
 * of it makes the shift expect $0 from that sale instead of $90–$100, and the closing count
 * shows a ~$90 overage. Refunds themselves are (documented) not counted, so the only
 * defensible expected figure here is somewhere in $90–$100, never $0.
 */
class Bug021PartiallyRefundedSaleDropsOutOfShiftCashTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_partially_refunded_cash_sale_still_counts_towards_expected_cash(): void
    {
        $cashier = User::factory()->create();
        $shift = Shift::create([
            'user_id' => $cashier->id, 'opened_at' => now(),
            'opening_cash_usd' => 0, 'opening_cash_lbp' => 0, 'status' => Shift::STATUS_OPEN,
        ]);
        Sale::create([
            'receipt_number' => 'R-0100', 'user_id' => $cashier->id, 'shift_id' => $shift->id,
            'subtotal_usd' => 100, 'total_usd' => 100, 'exchange_rate' => 90000,
            'payment_method' => 'cash_usd', 'amount_tendered_usd' => 100, 'change_usd' => 0,
            'status' => Sale::STATUS_PARTIAL_REFUND,
        ]);

        $totals = app(ShiftService::class)->totals($shift);

        $this->assertGreaterThanOrEqual(90.0, $totals['expected_usd'], "Expected cash is \${$totals['expected_usd']} although \$100 cash was taken and only \$10 refunded.");
        $this->assertLessThanOrEqual(100.0, $totals['expected_usd']);
        $this->assertSame(1, $totals['sales_count'], 'The partially refunded sale is missing from the shift summary.');
    }
}
