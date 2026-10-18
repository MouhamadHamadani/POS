<?php

namespace Tests\Feature\BugHunt;

use App\Models\Sale;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BUG-012: "Find sale" on the returns screen ignores its own status filter for receipt numbers.
 *
 * ReturnController::findSale() builds
 *   where(receipt_number = ?) OR whereHas(customer phone) AND whereNotIn(status, [voided, on_hold])
 * and SQL binds AND tighter than OR, so the status exclusion only guards the phone branch.
 * A voided (or held) sale looked up by receipt number is "found", the cashier is sent on to
 * the return form, and gets a bare 403 page instead of the "no sale found" message.
 */
class Bug012ReturnLookupFindsVoidedSaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_looking_up_a_voided_sale_by_receipt_number_reports_no_sale_found(): void
    {
        $cashier = User::factory()->create();
        $shift = Shift::create([
            'user_id' => $cashier->id, 'opened_at' => now(),
            'opening_cash_usd' => 0, 'opening_cash_lbp' => 0, 'status' => Shift::STATUS_OPEN,
        ]);
        Sale::create([
            'receipt_number' => 'R-VOIDED-1', 'user_id' => $cashier->id, 'shift_id' => $shift->id,
            'subtotal_usd' => 5, 'total_usd' => 5, 'exchange_rate' => 90000,
            'payment_method' => 'cash_usd', 'status' => Sale::STATUS_VOIDED,
        ]);

        $response = $this->actingAs($cashier)->post(route('returns.find-sale'), ['query' => 'R-VOIDED-1']);

        $response->assertSessionHasErrors('query');
        $this->assertStringNotContainsString('/returns/create/', (string) $response->headers->get('Location'));
    }
}
