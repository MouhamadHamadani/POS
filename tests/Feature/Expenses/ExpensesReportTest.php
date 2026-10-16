<?php

namespace Tests\Feature\Expenses;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\Tax;
use App\Models\User;
use App\Services\ExpenseService;
use App\Services\ReportService;
use App\Services\SaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Expenses by category, and the P&L that nets expenses off gross profit — against hand-computed figures. */
class ExpensesReportTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = User::factory()->manager()->create();
        Setting::set('exchange_rate', 90000, 'currency', 'float');
    }

    private function book(string $category, float $amount, string $date, string $currency = 'usd'): Expense
    {
        return app(ExpenseService::class)->create([
            'expense_category_id' => ExpenseCategory::where('name', $category)->value('id'),
            'date' => $date, 'currency' => $currency, 'amount' => $amount, 'paid_from' => 'outside',
        ], $this->manager);
    }

    /** Same two sales as RevenueReportTest: ex-VAT revenue 50.00, COGS 22.00, gross profit 28.00. */
    private function twoSales(): void
    {
        $vat = Tax::create(['name' => 'Lebanese VAT', 'rate' => 0.11, 'is_inclusive' => false, 'is_default' => true, 'is_active' => true]);
        $cashier = User::factory()->create(['max_discount_pct' => 100]);
        $shift = Shift::create(['user_id' => $cashier->id, 'opened_at' => now(), 'opening_cash_usd' => 0, 'opening_cash_lbp' => 0, 'status' => Shift::STATUS_OPEN]);
        $rice = Product::factory()->create(['name' => 'Rice 1kg', 'price_usd' => 10, 'cost_usd' => 4, 'stock_qty' => 100, 'tax_id' => $vat->id, 'is_taxable' => true]);
        $oil = Product::factory()->create(['name' => 'Olive Oil 1L', 'price_usd' => 25, 'cost_usd' => 10, 'stock_qty' => 100, 'tax_id' => $vat->id, 'is_taxable' => true]);

        $sales = app(SaleService::class);
        $sales->process(cart: [['product_id' => $rice->id, 'qty' => 3]], payment: ['method' => 'cash_usd', 'amount_usd' => 40.00], user: $cashier, shiftId: $shift->id);
        $sales->process(cart: [['product_id' => $oil->id, 'qty' => 1, 'discount_amount' => 5.00]], payment: ['method' => 'cash_usd', 'amount_usd' => 25.00], user: $cashier, shiftId: $shift->id);
    }

    public function test_net_profit_is_gross_profit_less_fixed_and_running_expenses(): void
    {
        $this->twoSales();
        $today = now()->format('Y-m-d');

        // fixed:   Rent $8.00 + Insurance 900,000 LBP ($10.00)   = 18.00
        // running: Electricity $2.50 + Water 135,000 LBP ($1.50) =  4.00
        $this->book('Rent', 8, $today);
        $this->book('Insurance', 900_000, $today, 'lbp');
        $this->book('Electricity', 2.5, $today);
        $this->book('Water', 135_000, $today, 'lbp');

        // none of these may count: another day, and a deleted bill
        $this->book('Fuel', 70, now()->subDays(3)->format('Y-m-d'));
        app(ExpenseService::class)->delete($this->book('Maintenance', 33, $today), $this->manager);

        $pnl = app(ReportService::class)->profitLoss(now()->startOfDay(), now()->endOfDay());

        $this->assertEqualsWithDelta(28.00, $pnl['gross_profit'], 0.0001);          // unchanged
        $this->assertEqualsWithDelta(18.00, $pnl['fixed_expenses'], 0.0001);
        $this->assertEqualsWithDelta(4.00, $pnl['running_expenses'], 0.0001);
        $this->assertEqualsWithDelta(22.00, $pnl['total_expenses'], 0.0001);
        $this->assertEqualsWithDelta(6.00, $pnl['net_profit'], 0.0001);             // 28 - 18 - 4
        $this->assertEqualsWithDelta(12.00, $pnl['net_margin_pct'], 0.0001);        // 6 / 50 ex-VAT revenue

        // every pre-existing key is still there with the same value
        foreach (['txn_count' => 2, 'gross_revenue' => 55.00, 'discounts' => 5.00, 'tax_collected' => 5.50, 'net_revenue' => 55.50,
                  'ex_vat_revenue' => 50.00, 'cogs' => 22.00, 'margin_pct' => 56.00] as $key => $value) {
            $this->assertEqualsWithDelta($value, $pnl[$key], 0.0001, $key);
        }

        $today = now()->format('Y-m-d');
        $this->actingAs($this->manager)->get("/reports/financial/pnl?from={$today}&to={$today}")->assertOk()
            ->assertSee('Fixed expenses')->assertSee('Running expenses')->assertSee('Net Profit')
            ->assertSee('18.00')->assertSee('4.00')->assertSee('6.00')->assertSee('12.0%');
    }

    public function test_expenses_follow_their_own_date_not_the_day_they_were_keyed_in(): void
    {
        $this->twoSales();
        $service = app(ReportService::class);

        // Keyed in today, but it is last month's electricity bill.
        $this->book('Electricity', 40, now()->subMonth()->format('Y-m-d'));
        $this->assertEqualsWithDelta(0, $service->profitLoss(now()->startOfDay(), now()->endOfDay())['running_expenses'], 0.0001);
        $this->assertEqualsWithDelta(40, $service->profitLoss(now()->subMonth()->startOfDay(), now()->subMonth()->endOfDay())['running_expenses'], 0.0001);

        // The range is inclusive of both end dates.
        $this->book('Rent', 5, now()->subDays(2)->format('Y-m-d'));
        $this->book('Rent', 7, now()->format('Y-m-d'));
        $this->assertEqualsWithDelta(12, $service->profitLoss(now()->subDays(2)->startOfDay(), now()->endOfDay())['fixed_expenses'], 0.0001);
    }

    public function test_net_figures_with_no_expenses_equal_the_gross_ones(): void
    {
        $this->twoSales();
        $pnl = app(ReportService::class)->profitLoss(now()->startOfDay(), now()->endOfDay());

        $this->assertEqualsWithDelta(0, $pnl['total_expenses'], 0.0001);
        $this->assertEqualsWithDelta($pnl['gross_profit'], $pnl['net_profit'], 0.0001);
        $this->assertEqualsWithDelta($pnl['margin_pct'], $pnl['net_margin_pct'], 0.0001);
    }

    public function test_expenses_by_category_totals_shares_and_months(): void
    {
        $this->book('Rent', 600, '2026-01-05');
        $this->book('Rent', 600, '2026-02-05');
        $this->book('Salaries', 900_000, '2026-02-25', 'lbp');          // $10.00
        $this->book('Electricity', 100, '2026-01-20');
        $this->book('Electricity', 140, '2026-03-02');
        $this->book('Fuel', 50, '2026-04-01');                          // outside the range
        app(ExpenseService::class)->delete($this->book('Water', 999, '2026-02-01'), $this->manager);

        $data = app(ReportService::class)->expensesByCategory(\Carbon\Carbon::parse('2026-01-01'), \Carbon\Carbon::parse('2026-03-31')->endOfDay());

        // fixed 1,210.00 (600+600+10), running 240.00 (100+140), total 1,450.00
        $this->assertEqualsWithDelta(1210.00, $data['fixed'], 0.0001);
        $this->assertEqualsWithDelta(240.00, $data['running'], 0.0001);
        $this->assertEqualsWithDelta(1450.00, $data['total'], 0.0001);

        $byName = collect($data['categories'])->keyBy('local_name');
        $this->assertSame(['Rent', 'Salaries', 'Electricity'], $byName->keys()->all());   // fixed first
        $this->assertEqualsWithDelta(1200.00, $byName['Rent']['total'], 0.0001);
        $this->assertEqualsWithDelta(82.8, $byName['Rent']['pct'], 0.0001);                // 1200 / 1450
        $this->assertEqualsWithDelta(0.7, $byName['Salaries']['pct'], 0.0001);
        $this->assertEqualsWithDelta(16.6, $byName['Electricity']['pct'], 0.0001);

        $this->assertSame(['2026-01', '2026-02', '2026-03'], $data['months']);
        $rent = $byName['Rent']['id'];
        $this->assertEqualsWithDelta(600, $data['monthly'][$rent]['2026-01'], 0.0001);
        $this->assertEqualsWithDelta(600, $data['monthly'][$rent]['2026-02'], 0.0001);
        $this->assertArrayNotHasKey('2026-03', $data['monthly'][$rent]);
        $this->assertEqualsWithDelta(700.00, $data['month_totals']['2026-01'], 0.0001);
        $this->assertEqualsWithDelta(610.00, $data['month_totals']['2026-02'], 0.0001);
        $this->assertEqualsWithDelta(140.00, $data['month_totals']['2026-03'], 0.0001);

        // a one-month range has no month table
        $single = app(ReportService::class)->expensesByCategory(\Carbon\Carbon::parse('2026-02-01'), \Carbon\Carbon::parse('2026-02-28')->endOfDay());
        $this->assertSame([], $single['months']);
        $this->assertEqualsWithDelta(610.00, $single['total'], 0.0001);
    }

    public function test_the_report_screen_and_exports(): void
    {
        $this->book('Rent', 600, '2026-01-05');
        $this->book('Electricity', 140, '2026-03-02');

        $this->actingAs($this->manager)->get('/reports/financial/expenses?from=2026-01-01&to=2026-03-31')
            ->assertOk()
            ->assertSee('Expenses by Category')
            ->assertSee('740.00')->assertSee('600.00')->assertSee('140.00')
            ->assertSee('81.1%')                                    // 600 / 740
            ->assertSee('By month')->assertSee('2026-02');

        $this->actingAs($this->manager)->get('/reports/financial/expenses?from=2026-03-01&to=2026-03-31')
            ->assertOk()->assertDontSee('By month');

        $csv = $this->actingAs($this->manager)->get('/reports/financial/expenses?from=2026-01-01&to=2026-03-31&format=xlsx');
        $csv->assertOk()->assertHeader('content-disposition');

        foreach (['en', 'ar'] as $lang) {
            $user = User::factory()->manager()->create(['language' => $lang]);
            $pdf = $this->actingAs($user)->get('/reports/financial/expenses?from=2026-01-01&to=2026-03-31&format=pdf');
            $pdf->assertOk();
            $this->assertStringStartsWith('%PDF', $pdf->getContent());
        }

        $this->actingAs($this->manager)->get('/reports')->assertSee('Expenses by Category');
        $this->actingAs($this->manager)->get('/reports/financial/expenses?from=2026-03-01&to=2026-01-01')->assertSessionHasErrors('to');
    }

    public function test_the_pnl_exports_carry_the_new_lines_in_both_languages(): void
    {
        $this->twoSales();
        $this->book('Rent', 8, now()->format('Y-m-d'));

        foreach (['en', 'ar'] as $lang) {
            $user = User::factory()->manager()->create(['language' => $lang]);
            foreach (['xlsx', 'pdf'] as $format) {
                $this->actingAs($user)->get("/reports/financial/pnl?format={$format}")->assertOk();
            }
        }

        $arabic = User::factory()->manager()->create(['language' => 'ar']);
        $this->actingAs($arabic)->get('/reports/financial/pnl')->assertOk()
            ->assertSee('صافي الربح')->assertSee('المصاريف الثابتة')->assertDontSee('Net Profit');
    }

    public function test_exports_are_blocked_in_a_demo_build_but_the_report_shows(): void
    {
        config(['pos.demo_mode' => true]);

        $this->actingAs($this->manager)->get('/reports/financial/expenses')->assertOk();
        $this->actingAs($this->manager)->get('/reports/financial/expenses?format=pdf')->assertSessionHasErrors('demo');
        $this->actingAs($this->manager)->get('/reports/financial/expenses?format=xlsx')->assertSessionHasErrors('demo');
    }
}
