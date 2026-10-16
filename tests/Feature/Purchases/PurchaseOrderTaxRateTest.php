<?php

namespace Tests\Feature\Purchases;

use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** BUG-010: a PO line's tax is stored as a 0..1 fraction; the form shows and takes a percentage. */
class PurchaseOrderTaxRateTest extends TestCase
{
    use RefreshDatabase;

    private function storePo(float|int $taxRate)
    {
        $supplier = Supplier::create(['name' => 'Acme Foods', 'payment_terms' => 'NET30', 'is_active' => true]);
        $product = Product::factory()->create();

        return $this->actingAs(User::factory()->manager()->create())->post(route('purchases.store'), [
            'supplier_id' => $supplier->id,
            'status' => 'draft',
            'items' => [['product_id' => $product->id, 'qty_ordered' => 10, 'cost_usd' => 5, 'tax_rate' => $taxRate]],
        ]);
    }

    public function test_a_fraction_is_stored_and_totalled_as_that_percentage(): void
    {
        $this->storePo(0.11)->assertSessionHasNoErrors();

        $this->assertEqualsWithDelta(0.11, (float) PurchaseOrderItem::first()->tax_rate, 0.00001);
        $this->assertEqualsWithDelta(55.5, (float) PurchaseOrder::first()->total_usd, 0.001); // 10 x $5 = $50 + 11%
    }

    public function test_a_rate_above_one_is_refused_with_a_clear_message_and_nothing_is_saved(): void
    {
        $this->storePo(11)->assertSessionHasErrors(['items.0.tax_rate' => __('messages.po_tax_rate_range')]);

        $this->assertSame(0, PurchaseOrder::count());
        $this->assertSame(0, PurchaseOrderItem::count());
    }

    public function test_the_edit_form_shows_the_stored_fraction_as_a_percentage(): void
    {
        $this->storePo(0.11);
        $po = PurchaseOrder::firstOrFail();

        $html = $this->actingAs(User::factory()->manager()->create())->get(route('purchases.edit', $po))->assertOk()->getContent();

        $this->assertStringContainsString('"tax_pct":11', $html);
        $this->assertStringNotContainsString('"tax_rate":0.11', $html);
    }
}
