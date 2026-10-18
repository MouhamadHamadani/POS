<?php

namespace Tests\Feature\BugHunt;

use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BUG-010: the purchase-order line "Tax %" column takes a 0..1 fraction, and accepts 11 as 1100%.
 *
 * The column header reads "Tax %" and the PO page shows the stored rate as `rate * 100 %`
 * ("11.00%"), but the input is pre-filled with the raw fraction (0.11) and the rule is
 * `nullable|numeric|min:0` with no upper bound. Typing 11 (what the header asks for)
 * saves a 1100% tax: the line total, PO total and the supplier balance owed on receipt
 * are inflated twelve-fold. VAT everywhere else is capped to 0..1 (Settings > Tax).
 */
class Bug010PurchaseOrderTaxRateAcceptsPercentTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_tax_rate_typed_as_a_percentage_never_becomes_a_rate_above_one(): void
    {
        $supplier = Supplier::create(['name' => 'Acme Foods', 'payment_terms' => 'NET30', 'is_active' => true]);
        $product = Product::factory()->create();
        $manager = User::factory()->manager()->create();

        $this->actingAs($manager)->post(route('purchases.store'), [
            'supplier_id' => $supplier->id,
            'status' => 'draft',
            'items' => [
                ['product_id' => $product->id, 'qty_ordered' => 10, 'cost_usd' => 5, 'tax_rate' => 11],
            ],
        ]);

        // Either the request is refused or 11 is read as 11%: what must not happen is a stored rate of 11.
        $stored = (float) (PurchaseOrderItem::max('tax_rate') ?? 0);
        $this->assertLessThanOrEqual(1.0, $stored, "The line was saved with a tax rate of {$stored} (= ".($stored * 100).'%).');

        $po = PurchaseOrder::first();
        if ($po) {
            // 10 x $5 = $50 net; even at 100% tax the total could not pass $100.
            $this->assertLessThanOrEqual(100.0, (float) $po->total_usd);
        }
    }
}
