<?php

namespace Tests\Feature\BugHunt;

use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\BugHunt\Concerns\SubmitsRenderedForms;
use Tests\TestCase;

/**
 * BUG-005: the "Restock" checkbox on the return form cannot be turned off.
 *
 * The checkbox starts ticked. Un-ticking it (damaged goods) makes the browser send nothing
 * for that line, and ReturnController::store() treats a missing `restock` key as "restock".
 * The damaged item is added back to sellable stock and the movement log says it came back.
 * The comment in the controller says the caller "must explicitly pass restock=0", but the
 * form has no hidden 0 to pass.
 */
class Bug005ReturnRestockCheckboxCannotBeUntickedTest extends TestCase
{
    use RefreshDatabase;
    use SubmitsRenderedForms;

    public function test_unticking_restock_on_a_return_line_leaves_stock_unchanged(): void
    {
        $manager = User::factory()->manager()->create();
        $shift = Shift::create([
            'user_id' => $manager->id, 'opened_at' => now(),
            'opening_cash_usd' => 0, 'opening_cash_lbp' => 0, 'status' => Shift::STATUS_OPEN,
        ]);
        $product = Product::factory()->create(['stock_qty' => 10, 'track_stock' => true]);
        $sale = Sale::create([
            'receipt_number' => 'R-0001', 'user_id' => $manager->id, 'shift_id' => $shift->id,
            'subtotal_usd' => 20, 'total_usd' => 20, 'exchange_rate' => 90000,
            'payment_method' => 'cash_usd', 'status' => Sale::STATUS_COMPLETED,
        ]);
        SaleItem::create([
            'sale_id' => $sale->id, 'product_id' => $product->id, 'product_name' => $product->name,
            'qty' => 2, 'unit_price_usd' => 10, 'line_total_usd' => 20,
        ]);

        $html = $this->actingAs($manager)->get(route('returns.create', $sale))->assertOk()->getContent();

        $this->submitRenderedForm(
            $html,
            route('returns.store', $sale),
            uncheck: ['items[0][restock]'],
            set: ['items[0][qty_returned]' => '1'],
        )->assertSessionHasNoErrors();

        $this->assertSame(1, \App\Models\ReturnTransaction::count(), 'The return was not created.');
        $this->assertEquals(10, (float) $product->fresh()->stock_qty, 'A line returned with Restock unticked was added back to stock.');
    }
}
