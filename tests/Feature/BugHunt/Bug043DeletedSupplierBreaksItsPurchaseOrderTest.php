<?php

namespace Tests\Feature\BugHunt;

use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BUG-043: SupplierController::destroy() soft-deletes a supplier with no check for open purchase orders.
 * PurchaseOrder::supplier() is a plain belongsTo (soft-deleted rows are hidden), so the PO page does
 * `$po->supplier->name` on null (HTTP 500) and receive() ends in `$purchase->supplier->increment(...)` on null
 * (HTTP 500, the whole receipt rolls back). Goods that physically arrived for a sent PO can never be booked in.
 * Same class as BUG-037 (deactivated supplier) but a crash, not a missing option.
 */
class Bug043DeletedSupplierBreaksItsPurchaseOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_sent_purchase_order_of_a_deleted_supplier_can_still_be_opened_and_received_or_the_delete_is_refused(): void
    {
        $manager = User::factory()->manager()->create();
        $admin = User::factory()->admin()->create();
        $supplier = Supplier::create(['name' => 'Acme Foods', 'payment_terms' => 'NET30', 'is_active' => true]);
        $product = Product::factory()->create(['stock_qty' => 0, 'track_stock' => true]);
        $this->actingAs($manager)->post(route('purchases.store'), [
            'supplier_id' => $supplier->id, 'status' => 'sent',
            'items' => [['product_id' => $product->id, 'qty_ordered' => 10, 'cost_usd' => 5]],
        ])->assertSessionHasNoErrors();
        $po = PurchaseOrder::with('items')->firstOrFail();

        $this->actingAs($admin)->delete(route('suppliers.destroy', $supplier));

        if (Supplier::find($supplier->id)) {
            // Refusing to delete a supplier that still has open purchase orders is a correct fix.
            $this->addToAssertionCount(1);

            return;
        }

        // The delete went through, so the open PO must still be usable.
        $this->actingAs($manager)->get(route('purchases.show', $po))->assertOk();

        $this->actingAs($manager)->post(route('purchases.receive', $po), [
            'receipts' => [['item_id' => $po->items->first()->id, 'qty' => 10]],
        ])->assertRedirect(route('purchases.show', $po))->assertSessionHasNoErrors();

        $this->assertEquals(10.0, (float) $product->fresh()->stock_qty, 'The delivered goods were not booked into stock.');
        $this->assertSame('received', $po->fresh()->status);
    }
}
