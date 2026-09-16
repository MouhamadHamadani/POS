<?php

namespace Tests\Feature\Reports;

use App\Models\Product;
use App\Models\Sale;
use App\Models\Shift;
use App\Models\Tax;
use App\Models\User;
use App\Services\ReportService;
use App\Services\SaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Daily sales and P&L, checked against numbers computed by hand in the test
 * body rather than against whatever the service happens to return.
 */
class RevenueReportTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;
    private Shift $shift;
    private Product $rice;
    private Product $oil;

    protected function setUp(): void
    {
        parent::setUp();

        $vat = Tax::create([
            'name' => 'Lebanese VAT', 'rate' => 0.11, 'is_inclusive' => false,
            'is_default' => true, 'is_active' => true,
        ]);

        $this->cashier = User::factory()->create(['role' => User::ROLE_CASHIER]);
        $this->shift = Shift::create([
            'user_id' => $this->cashier->id, 'opened_at' => now(),
            'opening_cash_usd' => 0, 'opening_cash_lbp' => 0, 'status' => Shift::STATUS_OPEN,
        ]);

        $this->rice = Product::factory()->create([
            'name' => 'Rice 1kg', 'price_usd' => 10.00, 'cost_usd' => 4.00,
            'stock_qty' => 100, 'tax_id' => $vat->id, 'is_taxable' => true,
        ]);
        $this->oil = Product::factory()->create([
            'name' => 'Olive Oil 1L', 'price_usd' => 25.00, 'cost_usd' => 10.00,
            'stock_qty' => 100, 'tax_id' => $vat->id, 'is_taxable' => true,
        ]);
    }

    /**
     * Two sales, hand-computed:
     *
     *   Sale 1: 3 x Rice @ 10.00        -> net 30.00, VAT 3.30, total 33.30
     *   Sale 2: 1 x Oil  @ 25.00 - 5.00 -> net 20.00, VAT 2.20, total 22.20
     *
     *   subtotal 55.00 | discounts 5.00 | VAT 5.50 | charged 55.50
     *   COGS  3x4.00 + 1x10.00 = 22.00
     *   revenue ex-VAT 50.00 -> gross profit 28.00 -> margin 56.00%
     */
    private function twoSales(): void
    {
        $sales = app(SaleService::class);

        $sales->process(
            cart: [['product_id' => $this->rice->id, 'qty' => 3, 'unit_price' => 10.00]],
            payment: ['method' => 'cash_usd', 'amount_usd' => 40.00],
            userId: $this->cashier->id,
            shiftId: $this->shift->id,
        );

        $sales->process(
            cart: [['product_id' => $this->oil->id, 'qty' => 1, 'unit_price' => 25.00, 'discount_amount' => 5.00]],
            payment: ['method' => 'cash_usd', 'amount_usd' => 25.00],
            userId: $this->cashier->id,
            shiftId: $this->shift->id,
        );
    }

    public function test_daily_sales_matches_the_hand_computed_totals(): void
    {
        $this->twoSales();

        $data = app(ReportService::class)->dailySales(now()->startOfDay(), now()->endOfDay());

        $this->assertSame(2, (int) $data['totals']['txn_count']);
        $this->assertEqualsWithDelta(55.00, (float) $data['totals']['subtotal'], 0.0001);
        $this->assertEqualsWithDelta(5.00, (float) $data['totals']['discount'], 0.0001);
        $this->assertEqualsWithDelta(5.50, (float) $data['totals']['tax'], 0.0001);
        $this->assertEqualsWithDelta(55.50, (float) $data['totals']['total'], 0.0001);

        $this->assertCount(1, $data['rows']);
        $this->assertSame(now()->toDateString(), $data['rows'][0]['day']);

        $this->actingAs(User::factory()->manager()->create())
            ->get('/reports/sales/daily')
            ->assertOk()
            ->assertSee('55.50');
    }

    public function test_profit_loss_matches_the_hand_computed_totals(): void
    {
        $this->twoSales();

        $pnl = app(ReportService::class)->profitLoss(now()->startOfDay(), now()->endOfDay());

        $this->assertSame(2, $pnl['txn_count']);
        $this->assertEqualsWithDelta(55.00, $pnl['gross_revenue'], 0.0001);
        $this->assertEqualsWithDelta(5.00, $pnl['discounts'], 0.0001);
        $this->assertEqualsWithDelta(5.50, $pnl['tax_collected'], 0.0001);
        $this->assertEqualsWithDelta(55.50, $pnl['net_revenue'], 0.0001);
        $this->assertEqualsWithDelta(50.00, $pnl['ex_vat_revenue'], 0.0001);
        $this->assertEqualsWithDelta(22.00, $pnl['cogs'], 0.0001);
        $this->assertEqualsWithDelta(28.00, $pnl['gross_profit'], 0.0001);
        $this->assertEqualsWithDelta(56.00, $pnl['margin_pct'], 0.0001);

        $this->actingAs(User::factory()->manager()->create())
            ->get('/reports/financial/pnl')
            ->assertOk()
            ->assertSee('55.50')
            ->assertSee('28.00');
    }

    public function test_vat_is_never_counted_as_profit(): void
    {
        // Regression: gross profit used to be revenue-including-VAT minus COGS
        // on inclusive-tax setups, and sales-by-product did it on every setup.
        $this->twoSales();

        $pnl = app(ReportService::class)->profitLoss(now()->startOfDay(), now()->endOfDay());
        $this->assertEqualsWithDelta(
            $pnl['net_revenue'] - $pnl['tax_collected'] - $pnl['cogs'],
            $pnl['gross_profit'],
            0.0001
        );

        // Rice: 30.00 ex-VAT revenue - 12.00 COGS = 18.00 profit, 60% margin.
        // (33.30 - 12.00 = 21.30 would be the VAT-inflated answer.)
        $rows = collect(app(ReportService::class)
            ->salesByProduct(now()->startOfDay(), now()->endOfDay())['rows'])
            ->keyBy('product_name');

        $this->assertEqualsWithDelta(30.00, (float) $rows['Rice 1kg']['revenue'], 0.0001);
        $this->assertEqualsWithDelta(12.00, (float) $rows['Rice 1kg']['cogs'], 0.0001);
        $this->assertEqualsWithDelta(18.00, (float) $rows['Rice 1kg']['profit'], 0.0001);
        $this->assertEqualsWithDelta(60.00, (float) $rows['Rice 1kg']['margin_pct'], 0.0001);

        // Olive oil: 20.00 ex-VAT revenue - 10.00 COGS = 10.00, 50% margin.
        $this->assertEqualsWithDelta(20.00, (float) $rows['Olive Oil 1L']['revenue'], 0.0001);
        $this->assertEqualsWithDelta(10.00, (float) $rows['Olive Oil 1L']['profit'], 0.0001);
        $this->assertEqualsWithDelta(50.00, (float) $rows['Olive Oil 1L']['margin_pct'], 0.0001);
    }

    public function test_inclusive_vat_revenue_is_reported_net_of_tax(): void
    {
        Tax::query()->update(['is_inclusive' => true]);

        // 111.00 shelf price at 11% inclusive = 100.00 net + 11.00 VAT.
        $inclusiveItem = Product::factory()->create([
            'name' => 'Inclusive Item', 'price_usd' => 111.00, 'cost_usd' => 60.00,
            'stock_qty' => 10, 'tax_id' => Tax::first()->id, 'is_taxable' => true,
        ]);

        app(SaleService::class)->process(
            cart: [['product_id' => $inclusiveItem->id, 'qty' => 1, 'unit_price' => 111.00]],
            payment: ['method' => 'cash_usd', 'amount_usd' => 111.00],
            userId: $this->cashier->id,
            shiftId: $this->shift->id,
        );

        $pnl = app(ReportService::class)->profitLoss(now()->startOfDay(), now()->endOfDay());

        $this->assertEqualsWithDelta(111.00, $pnl['net_revenue'], 0.0001);
        $this->assertEqualsWithDelta(11.00, $pnl['tax_collected'], 0.0001);
        $this->assertEqualsWithDelta(100.00, $pnl['ex_vat_revenue'], 0.0001);
        $this->assertEqualsWithDelta(60.00, $pnl['cogs'], 0.0001);
        $this->assertEqualsWithDelta(40.00, $pnl['gross_profit'], 0.0001);
    }

    public function test_voided_sales_are_excluded_from_revenue(): void
    {
        $this->twoSales();
        $voided = Sale::orderByDesc('id')->first();   // the 22.20 sale

        app(SaleService::class)->voidSale($voided, $this->cashier->id, 'customer changed mind');

        $pnl = app(ReportService::class)->profitLoss(now()->startOfDay(), now()->endOfDay());

        $this->assertSame(1, $pnl['txn_count']);
        $this->assertEqualsWithDelta(33.30, $pnl['net_revenue'], 0.0001);
        $this->assertEqualsWithDelta(30.00, $pnl['ex_vat_revenue'], 0.0001);
        $this->assertEqualsWithDelta(12.00, $pnl['cogs'], 0.0001);
        $this->assertEqualsWithDelta(18.00, $pnl['gross_profit'], 0.0001);

        // Stock came back too.
        $this->assertEqualsWithDelta(100, (float) $this->oil->fresh()->stock_qty, 0.0001);

        $daily = app(ReportService::class)->dailySales(now()->startOfDay(), now()->endOfDay());
        $this->assertEqualsWithDelta(33.30, (float) $daily['totals']['total'], 0.0001);
    }

    public function test_sales_outside_the_range_are_excluded(): void
    {
        $this->twoSales();

        // Push one sale back a week (query builder: created_at is not fillable).
        Sale::whereKey(Sale::max('id'))->update(['created_at' => now()->subWeek()]);

        $pnl = app(ReportService::class)->profitLoss(now()->startOfDay(), now()->endOfDay());
        $this->assertSame(1, $pnl['txn_count']);
        $this->assertEqualsWithDelta(33.30, $pnl['net_revenue'], 0.0001);

        $wider = app(ReportService::class)->profitLoss(now()->subMonth(), now()->endOfDay());
        $this->assertSame(2, $wider['txn_count']);
        $this->assertEqualsWithDelta(55.50, $wider['net_revenue'], 0.0001);
    }

    public function test_pnl_pdf_export_carries_the_same_numbers_as_the_screen(): void
    {
        $this->twoSales();
        $manager = User::factory()->manager()->create();

        $data = app(ReportService::class)->profitLoss(now()->startOfDay(), now()->endOfDay());

        $pdfHtml = view('reports.pnl-pdf', $data + [
            'from' => now()->startOfDay(), 'to' => now()->endOfDay(), 'title' => 'Profit & Loss',
        ])->render();

        $this->assertStringContainsString('55.50', $pdfHtml);  // net revenue
        $this->assertStringContainsString('28.00', $pdfHtml);  // gross profit
        $this->assertStringContainsString('22.00', $pdfHtml);  // cogs

        $response = $this->actingAs($manager)->get('/reports/financial/pnl?format=pdf');
        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
    }
}
