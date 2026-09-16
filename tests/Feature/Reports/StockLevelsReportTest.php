<?php

namespace Tests\Feature\Reports;

use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Shift;
use App\Models\Supplier;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Stock levels report: the quantities must be the ones left after real sales,
 * goods receipts and manual adjustments, and the PDF export must carry the
 * same numbers as the screen.
 */
class StockLevelsReportTest extends TestCase
{
    use RefreshDatabase;

    private function manager(): User
    {
        return User::factory()->manager()->create();
    }

    public function test_quantities_reflect_sales_receipts_and_adjustments(): void
    {
        $manager = $this->manager();
        $vat = Tax::create([
            'name' => 'Lebanese VAT', 'rate' => 0.11, 'is_inclusive' => false,
            'is_default' => true, 'is_active' => true,
        ]);

        $rice = Product::factory()->create([
            'name' => 'Rice 1kg', 'sku' => 'RICE-1', 'price_usd' => 10.00, 'cost_usd' => 4.00,
            'stock_qty' => 100, 'min_stock' => 10, 'tax_id' => $vat->id, 'is_taxable' => true,
        ]);
        $sugar = Product::factory()->create([
            'name' => 'Sugar 1kg', 'sku' => 'SUGAR-1', 'price_usd' => 5.00, 'cost_usd' => 2.00,
            'stock_qty' => 8, 'min_stock' => 10, 'is_taxable' => false,
        ]);

        // --- a sale: 5 x Rice -> 100 - 5 = 95
        Shift::create([
            'user_id' => $manager->id, 'opened_at' => now(),
            'opening_cash_usd' => 0, 'opening_cash_lbp' => 0, 'status' => Shift::STATUS_OPEN,
        ]);
        $this->actingAs($manager)->postJson('/pos/api/sales', [
            'cart' => [['product_id' => $rice->id, 'qty' => 5, 'unit_price' => 10.00]],
            'payment' => ['method' => 'cash_usd', 'amount_usd' => 60.00],
        ])->assertCreated();

        // --- a goods receipt: 20 x Sugar -> 8 + 20 = 28
        $supplier = Supplier::create(['name' => 'Wholesaler', 'is_active' => true, 'balance' => 0]);
        $po = PurchaseOrder::create([
            'po_number' => 'PO-0001', 'supplier_id' => $supplier->id, 'user_id' => $manager->id,
            'status' => 'sent', 'subtotal_usd' => 40, 'total_usd' => 40,
        ]);
        $poItem = PurchaseOrderItem::create([
            'purchase_order_id' => $po->id, 'product_id' => $sugar->id, 'product_name' => $sugar->name,
            'qty_ordered' => 20, 'qty_received' => 0, 'cost_usd' => 2.00, 'line_total_usd' => 40,
        ]);
        $this->actingAs($manager)->post("/purchase-orders/{$po->id}/receive", [
            'receipts' => [['item_id' => $poItem->id, 'qty' => 20]],
        ])->assertRedirect();

        // --- a manual adjustment: 3 x Rice written off -> 95 - 3 = 92
        $this->actingAs($manager)->post("/products/{$rice->id}/adjust-stock", [
            'action' => 'remove', 'qty' => 3, 'reason' => 'Damaged sack',
        ])->assertRedirect();

        $this->assertEqualsWithDelta(92, (float) $rice->fresh()->stock_qty, 0.0001);
        $this->assertEqualsWithDelta(28, (float) $sugar->fresh()->stock_qty, 0.0001);

        // Hand-computed report totals:
        //   Rice  92 x 4.00 = 368.00 cost / 92 x 10.00 = 920.00 retail
        //   Sugar 28 x 2.00 =  56.00 cost / 28 x  5.00 = 140.00 retail
        //   totals: 424.00 cost, 1,060.00 retail, 636.00 margin
        $data = app(\App\Services\ReportService::class)->stockLevels();

        $this->assertEqualsWithDelta(424.00, (float) $data['totals']['value_cost'], 0.0001);
        $this->assertEqualsWithDelta(1060.00, (float) $data['totals']['value_retail'], 0.0001);
        $this->assertEqualsWithDelta(636.00, (float) $data['totals']['margin'], 0.0001);
        $this->assertSame(2, $data['totals']['count']);

        $this->actingAs($manager)->get('/reports/inventory/stock-levels')
            ->assertOk()
            ->assertSee('Rice 1kg')
            ->assertSee('368.00')   // Rice cost value
            ->assertSee('920.00')   // Rice retail value
            ->assertSee('424.00')   // total cost value
            ->assertSee('1,060.00') // total retail value
            ->assertSee('636.00');  // potential margin
    }

    public function test_status_column_flags_low_and_out_of_stock(): void
    {
        $manager = $this->manager();

        Product::factory()->create(['name' => 'Plenty', 'stock_qty' => 100, 'min_stock' => 10]);
        Product::factory()->create(['name' => 'Running Low', 'stock_qty' => 8, 'min_stock' => 10]);
        Product::factory()->create(['name' => 'All Gone', 'stock_qty' => 0, 'min_stock' => 5]);

        $rows = collect(app(\App\Services\ReportService::class)->stockLevels()['rows'])
            ->keyBy('name');

        $this->assertSame('ok', $rows['Plenty']['status']);
        $this->assertSame('low', $rows['Running Low']['status']);
        $this->assertSame('out', $rows['All Gone']['status']);

        $lowOnly = app(\App\Services\ReportService::class)->stockLevels('low')['rows'];
        $this->assertEqualsCanonicalizing(
            ['Running Low', 'All Gone'],
            array_column($lowOnly, 'name')
        );

        $this->actingAs($manager)->get('/reports/inventory/stock-levels?status_filter=out')
            ->assertOk()
            ->assertSee('All Gone')
            ->assertDontSee('Running Low');
    }

    public function test_untracked_and_inactive_products_are_excluded(): void
    {
        Product::factory()->create(['name' => 'Tracked', 'stock_qty' => 10, 'cost_usd' => 1, 'price_usd' => 2]);
        Product::factory()->create(['name' => 'Service Item', 'track_stock' => false, 'stock_qty' => 999]);
        Product::factory()->create(['name' => 'Discontinued', 'is_active' => false, 'stock_qty' => 999]);

        $names = array_column(app(\App\Services\ReportService::class)->stockLevels()['rows'], 'name');

        $this->assertSame(['Tracked'], $names);
    }

    public function test_pdf_export_carries_the_same_numbers_as_the_screen(): void
    {
        $manager = $this->manager();
        Product::factory()->create([
            'name' => 'Rice 1kg', 'sku' => 'RICE-1', 'stock_qty' => 92,
            'cost_usd' => 4.00, 'price_usd' => 10.00, 'min_stock' => 10,
        ]);

        $data = app(\App\Services\ReportService::class)->stockLevels();

        // dompdf renders this exact Blade view; asserting on it compares the
        // export's numbers without parsing a PDF binary.
        $pdfHtml = view('reports.stock-levels-pdf', $data + [
            'status_filter' => null,
            'title' => 'Inventory Stock Levels',
        ])->render();

        $this->assertStringContainsString('Rice 1kg', $pdfHtml);
        $this->assertStringContainsString('368.00', $pdfHtml);   // stock value at cost
        $this->assertStringContainsString('920.00', $pdfHtml);   // stock value at retail
        $this->assertStringContainsString('92 pcs', $pdfHtml);

        $this->actingAs($manager)->get('/reports/inventory/stock-levels')
            ->assertOk()
            ->assertSee('368.00')
            ->assertSee('920.00');

        // And the real export route produces a PDF.
        $response = $this->actingAs($manager)->get('/reports/inventory/stock-levels?format=pdf');
        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
    }
}
