<?php

namespace Tests\Feature\BugHunt;

use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BUG-037 (surfaced by the BUG-003 fix, which made it possible to deactivate a supplier): PurchaseOrderController::edit()
 * lists only active suppliers, so the edit form of a draft/sent PO whose supplier has since been deactivated
 * has no option for its own supplier. The select is `required` with an empty first option, so the user cannot save any
 * change to the PO (date, notes, a line) without re-pointing it at a different supplier. Same class as BUG-004: a
 * select built from a subset must keep the record's current value (the expense form already does this).
 */
class Bug037PurchaseOrderEditHidesDeactivatedSupplierTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_edit_form_of_a_po_keeps_its_deactivated_supplier_selected(): void
    {
        $manager = User::factory()->manager()->create();
        $supplier = Supplier::create(['name' => 'Acme Foods', 'payment_terms' => 'NET30', 'is_active' => true]);
        $product = Product::factory()->create();

        $this->actingAs($manager)->post(route('purchases.store'), [
            'supplier_id' => $supplier->id, 'status' => 'draft',
            'items' => [['product_id' => $product->id, 'qty_ordered' => 10, 'cost_usd' => 5]],
        ])->assertSessionHasNoErrors();
        $po = \App\Models\PurchaseOrder::firstOrFail();

        // The supplier is retired while the PO is still open.
        $supplier->update(['is_active' => false]);

        $html = $this->actingAs($manager)->get(route('purchases.edit', $po))->assertOk()->getContent();

        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
        libxml_clear_errors();
        $option = (new DOMXPath($dom))->query("//select[@name='supplier_id']/option[@value='{$supplier->id}']")->item(0);

        $this->assertNotNull($option, "The PO's own supplier is not offered on its edit form, so the form cannot be saved without changing the supplier.");
        $this->assertTrue($option->hasAttribute('selected'), "The PO's supplier is offered but not selected.");
    }
}
