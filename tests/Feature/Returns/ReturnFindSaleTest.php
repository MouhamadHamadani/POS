<?php

namespace Tests\Feature\Returns;

use App\Models\Customer;
use App\Models\Sale;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BUG-012: "Find sale" applies its status filter to a lookup by receipt number as well as by phone,
 * so a sale the return form would refuse is "not found" rather than a bare 403 page.
 */
class ReturnFindSaleTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;

    private Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cashier = User::factory()->create();
        $this->shift = Shift::create([
            'user_id' => $this->cashier->id, 'opened_at' => now(),
            'opening_cash_usd' => 0, 'opening_cash_lbp' => 0, 'status' => Shift::STATUS_OPEN,
        ]);
    }

    private function sale(string $receipt, string $status, ?Customer $customer = null): Sale
    {
        return Sale::create([
            'receipt_number' => $receipt, 'user_id' => $this->cashier->id, 'shift_id' => $this->shift->id,
            'customer_id' => $customer?->id, 'subtotal_usd' => 5, 'total_usd' => 5, 'exchange_rate' => 90000,
            'payment_method' => 'cash_usd', 'status' => $status,
        ]);
    }

    private function find(string $query)
    {
        return $this->actingAs($this->cashier)->post(route('returns.find-sale'), ['query' => $query]);
    }

    public function test_a_completed_sale_is_found_by_receipt_number(): void
    {
        $sale = $this->sale('R-OK-1', Sale::STATUS_COMPLETED);

        $this->find('R-OK-1')->assertRedirect(route('returns.create', ['sale' => $sale->id]));
    }

    public function test_voided_held_and_fully_refunded_sales_are_not_found_by_receipt_number(): void
    {
        foreach ([Sale::STATUS_VOIDED, Sale::STATUS_ON_HOLD, Sale::STATUS_REFUNDED] as $i => $status) {
            $this->sale("R-NO-$i", $status);

            $this->find("R-NO-$i")->assertSessionHasErrors('query');
        }
    }

    public function test_by_phone_the_latest_returnable_sale_is_found(): void
    {
        $customer = Customer::create(['name' => 'Rami', 'phone' => '03123456', 'customer_group' => 'retail', 'is_active' => true, 'credit_limit' => 0]);
        $good = $this->sale('R-PH-1', Sale::STATUS_COMPLETED, $customer);
        $this->sale('R-PH-2', Sale::STATUS_VOIDED, $customer);

        $this->find('03123456')->assertRedirect(route('returns.create', ['sale' => $good->id]));
    }
}
