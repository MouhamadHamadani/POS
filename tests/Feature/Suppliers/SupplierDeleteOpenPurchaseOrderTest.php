<?php

namespace Tests\Feature\Suppliers;

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BUG-043: a supplier with an open purchase order (draft / sent / partial) cannot be deleted, and a purchase
 * order whose supplier is already deleted still opens and can be received.
 */
class SupplierDeleteOpenPurchaseOrderTest extends TestCase
{
    use RefreshDatabase;

    private function supplierWithPo(string $status): array
    {
        $supplier = Supplier::create(['name' => 'Acme Foods', 'payment_terms' => 'NET30', 'is_active' => true]);
        $product = Product::factory()->create(['stock_qty' => 0, 'track_stock' => true]);
        $po = PurchaseOrder::create([
            'po_number' => 'PO-T-1', 'supplier_id' => $supplier->id, 'user_id' => User::factory()->manager()->create()->id,
            'status' => $status, 'subtotal_usd' => 50, 'total_usd' => 50,
        ]);
        $po->items()->create([
            'product_id' => $product->id, 'product_name' => $product->name, 'qty_ordered' => 10, 'cost_usd' => 5, 'line_total_usd' => 50,
        ]);

        return [$supplier, $po, $product];
    }

    public function test_a_supplier_with_an_open_order_is_not_deleted_and_nothing_is_logged(): void
    {
        foreach ([PurchaseOrder::STATUS_DRAFT, PurchaseOrder::STATUS_SENT, PurchaseOrder::STATUS_PARTIAL] as $status) {
            [$supplier] = $this->supplierWithPo($status);

            $this->actingAs(User::factory()->admin()->create())
                ->from(route('suppliers.edit', $supplier))
                ->delete(route('suppliers.destroy', $supplier))
                ->assertRedirect(route('suppliers.edit', $supplier))
                ->assertSessionHasErrors(['delete' => __('messages.supplier_has_open_pos')]);

            $this->assertNotSoftDeleted($supplier);
            PurchaseOrder::query()->delete();
        }
        $this->assertSame(0, AuditLog::where('action', 'delete')->where('model_type', Supplier::class)->count());
    }

    public function test_the_refusal_is_in_arabic_for_an_arabic_user(): void
    {
        [$supplier] = $this->supplierWithPo(PurchaseOrder::STATUS_SENT);

        $this->actingAs(User::factory()->admin()->create(['language' => 'ar']))
            ->delete(route('suppliers.destroy', $supplier))
            ->assertSessionHasErrors(['delete' => trans('messages.supplier_has_open_pos', [], 'ar')]);
    }

    public function test_a_supplier_whose_orders_are_all_finished_can_still_be_deleted(): void
    {
        foreach ([PurchaseOrder::STATUS_RECEIVED, PurchaseOrder::STATUS_CLOSED, PurchaseOrder::STATUS_CANCELLED] as $status) {
            [$supplier] = $this->supplierWithPo($status);

            $this->actingAs(User::factory()->admin()->create())
                ->delete(route('suppliers.destroy', $supplier))
                ->assertRedirect(route('suppliers.index'));

            $this->assertSoftDeleted($supplier);
            PurchaseOrder::query()->delete();
        }
    }

    public function test_an_open_order_of_an_already_deleted_supplier_opens_and_receives_into_stock(): void
    {
        [$supplier, $po, $product] = $this->supplierWithPo(PurchaseOrder::STATUS_SENT);
        $supplier->delete();
        $manager = User::factory()->manager()->create();

        $this->actingAs($manager)->get(route('purchases.show', $po))->assertOk()->assertSee('Acme Foods');
        $this->actingAs($manager)->get(route('purchases.index'))->assertOk()->assertSee('Acme Foods');

        $this->actingAs($manager)->post(route('purchases.receive', $po), [
            'receipts' => [['item_id' => $po->items->first()->id, 'qty' => 10]],
        ])->assertRedirect(route('purchases.show', $po))->assertSessionHasNoErrors();

        $this->assertEquals(10.0, (float) $product->fresh()->stock_qty);
        $this->assertSame('received', $po->fresh()->status);
        $this->assertEquals(50.0, (float) Supplier::withTrashed()->find($supplier->id)->balance);
    }
}
