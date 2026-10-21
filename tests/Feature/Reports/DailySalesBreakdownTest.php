<?php

namespace Tests\Feature\Reports;

use App\Models\Product;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\User;
use App\Services\ReportService;
use App\Services\SaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Under the daily sales table: takings by payment method and by channel. Read-only, on the same
 * sales as the table, so they add up to its total. Numbers are worked out by hand below.
 */
class DailySalesBreakdownTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;
    private Shift $shift;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::set('exchange_rate', 90000, 'currency', 'float');
        $this->cashier = User::factory()->create();
        $this->shift = Shift::create([
            'user_id' => $this->cashier->id, 'opened_at' => now(),
            'opening_cash_usd' => 0, 'opening_cash_lbp' => 0, 'status' => Shift::STATUS_OPEN,
        ]);
        $this->product = Product::factory()->create(['price_usd' => 10, 'cost_usd' => 4, 'stock_qty' => 100, 'is_taxable' => false]);
    }

    private function sell(array $payment, array $extra = []): Sale
    {
        return app(SaleService::class)->process(
            cart: [['product_id' => $this->product->id, 'qty' => 1]],
            payment: $payment,
            user: $this->cashier,
            shiftId: $this->shift->id,
            channel: $extra['channel'] ?? Sale::CHANNEL_ONSITE,
            channelSource: $extra['source'] ?? null,
        );
    }

    private function breakdown(): array
    {
        return app(ReportService::class)->dailySalesBreakdown(now()->startOfDay(), now()->endOfDay());
    }

    /**
     *   1. $10 cash, tendered $20   -> cash $10 (net of $10 change)
     *   2. $10 card
     *   3. $10 = $6 cash + $4 Wish, tendered $10 -> cash $6, Wish $4 (a split)
     *   4. $10 Wish, online
     *   5. $10 voided cash sale -> not in anything
     *
     *   revenue $40: cash 10 + 6 = 16, card 10, Wish 4 + 10 = 14, on account 0
     */
    private function seedSales(): void
    {
        $this->sell(['amount_usd' => 20]);
        $this->sell(['amount_card' => 10]);
        $this->sell(['amount_usd' => 10, 'amount_wish' => 4]);
        $this->sell(['amount_wish' => 10], ['channel' => 'online', 'source' => 'whatsapp']);
        app(SaleService::class)->voidSale($this->sell(['amount_usd' => 10]), $this->cashier->id, 'oops');
    }

    public function test_payment_breakdown_matches_the_hand_computed_numbers_and_adds_up_to_revenue(): void
    {
        $this->seedSales();

        $b = $this->breakdown();
        $by = collect($b['payments'])->keyBy('method');

        $this->assertEqualsWithDelta(40.00, $b['revenue'], 0.0001);
        $this->assertEqualsWithDelta(16.00, $by['cash']['usd'], 0.0001, 'cash is net of change');
        $this->assertEqualsWithDelta(10.00, $by['card']['usd'], 0.0001);
        $this->assertEqualsWithDelta(14.00, $by['wish']['usd'], 0.0001);
        $this->assertEqualsWithDelta(0.00, $by['credit']['usd'], 0.0001);
        $this->assertEqualsWithDelta($b['revenue'], array_sum(array_column($b['payments'], 'usd')), 0.0001);

        // How many sales used each method: the split counts under both of its methods.
        $this->assertSame(2, $by['cash']['sales']);
        $this->assertSame(1, $by['card']['sales']);
        $this->assertSame(2, $by['wish']['sales']);
        $this->assertEqualsWithDelta(40.0, $by['cash']['share'], 0.01);
        $this->assertEqualsWithDelta(35.0, $by['wish']['share'], 0.01);
    }

    public function test_the_breakdown_adds_up_with_lbp_rounding_because_cash_takes_the_remainder(): void
    {
        Setting::set('exchange_rate', 89500, 'currency', 'float');
        $odd = Product::factory()->create(['price_usd' => 7.77, 'cost_usd' => 3, 'stock_qty' => 10, 'is_taxable' => false]);

        app(SaleService::class)->process(
            cart: [['product_id' => $odd->id, 'qty' => 1]],
            payment: ['amount_lbp' => 400000, 'amount_wish' => 3.33, 'change_usd_out' => 0],
            user: $this->cashier, shiftId: $this->shift->id,
        );

        $b = $this->breakdown();
        $this->assertEqualsWithDelta(7.77, array_sum(array_column($b['payments'], 'usd')), 0.00005);
        $this->assertEqualsWithDelta(4.44, collect($b['payments'])->firstWhere('method', 'cash')['usd'], 0.00005);
    }

    public function test_channel_breakdown_counts_and_totals_each_channel(): void
    {
        $this->seedSales();

        $by = collect($this->breakdown()['channels'])->keyBy('channel');

        $this->assertSame(3, $by['onsite']['sales']);
        $this->assertEqualsWithDelta(30.0, $by['onsite']['usd'], 0.0001);
        $this->assertSame(1, $by['online']['sales']);
        $this->assertEqualsWithDelta(10.0, $by['online']['usd'], 0.0001);
        $this->assertEqualsWithDelta(25.0, $by['online']['share'], 0.01);
    }

    public function test_the_breakdowns_leave_the_daily_table_alone_and_agree_with_its_total(): void
    {
        $this->seedSales();

        $daily = app(ReportService::class)->dailySales(now()->startOfDay(), now()->endOfDay());

        $this->assertSame(4, (int) $daily['totals']['txn_count']);
        $this->assertEqualsWithDelta(40.0, (float) $daily['totals']['total'], 0.0001);
        $this->assertEqualsWithDelta((float) $daily['totals']['total'], $this->breakdown()['revenue'], 0.0001);
        $this->assertSame(['day', 'txn_count', 'subtotal', 'discount', 'tax', 'total'], array_keys($daily['rows'][0]));
    }

    public function test_an_empty_range_gives_empty_breakdowns_not_an_error(): void
    {
        $b = app(ReportService::class)->dailySalesBreakdown(now()->subYear()->startOfDay(), now()->subYear()->endOfDay());

        $this->assertSame(0.0, $b['revenue']);
        $this->assertSame([0.0, 0.0, 0.0, 0.0], array_column($b['payments'], 'usd'));
        $this->assertSame([0, 0], array_column($b['channels'], 'sales'));
    }

    // --- The screen -------------------------------------------------------------------------------

    public function test_the_page_shows_both_sections_with_the_channel_one_following_its_feature(): void
    {
        $this->seedSales();
        $manager = User::factory()->manager()->create();

        $this->actingAs($manager)->get('/reports/sales/daily')->assertOk()
            ->assertSee(__('reports.by_payment'))->assertSee(__('enums.payment_method.wish'))->assertSee('$14.00', false)
            ->assertSee(__('reports.by_channel'))->assertSee(__('enums.sale_channel.online'));

        Setting::set('tier', 'starter', 'license');
        $this->actingAs($manager)->get('/reports/sales/daily')->assertOk()
            ->assertSee(__('reports.by_payment'))->assertSee('$14.00', false)
            ->assertDontSee(__('reports.by_channel'))->assertDontSee(__('enums.sale_channel.online'));
    }

    public function test_the_page_renders_in_arabic(): void
    {
        $this->seedSales();
        $manager = User::factory()->manager()->create(['language' => 'ar']);

        $this->actingAs($manager)->get('/reports/sales/daily')->assertOk()
            ->assertSee(__('reports.by_payment', [], 'ar'))->assertSee(__('reports.by_channel', [], 'ar'))
            ->assertSee(__('enums.payment_method.wish', [], 'ar'))->assertSee(__('enums.sale_channel.online', [], 'ar'));
    }

    public function test_the_pdf_still_builds_in_both_languages_with_and_without_the_channel_section(): void
    {
        $this->seedSales();

        foreach (['en', 'ar'] as $locale) {
            $manager = User::factory()->manager()->create(['language' => $locale]);
            foreach (['premium', 'starter'] as $plan) {
                Setting::set('tier', $plan, 'license');

                $response = $this->actingAs($manager)->get('/reports/sales/daily?format=pdf')->assertOk();
                $this->assertSame('application/pdf', $response->headers->get('content-type'));
                $this->assertStringStartsWith('%PDF', $response->getContent());
            }
        }
    }

    public function test_the_arabic_pdf_html_reverses_the_breakdown_columns_and_shapes_the_headings(): void
    {
        $this->seedSales();
        $reports = app(ReportService::class);
        [$from, $to] = [now()->startOfDay(), now()->endOfDay()];
        $breakdown = $reports->dailySalesBreakdown($from, $to);

        app()->setLocale('ar');
        $html = view('reports.daily-sales-pdf', $reports->dailySales($from, $to) + [
            'from' => $from, 'to' => $to, 'title' => 'x',
            'byPayment' => $breakdown['payments'], 'byChannel' => $breakdown['channels'],
        ])->render();

        // The headings reach dompdf shaped (not as the logical Arabic string), and each breakdown table
        // starts with its numeric columns: the label column is last in RTL.
        $this->assertStringNotContainsString(__('reports.by_payment', [], 'ar'), $html);
        $this->assertSame(2, substr_count($html, '<h2>'));
        foreach (explode('<h2>', $html) as $i => $section) {
            if ($i === 0) {
                continue;
            }
            $firstNumeric = strpos($section, '<th class="num">');
            $firstLabel = strpos($section, '<th class="">');
            $this->assertNotFalse($firstNumeric);
            $this->assertLessThan($firstLabel, $firstNumeric, 'columns are emitted in reverse for Arabic');
        }
    }

    public function test_the_spreadsheet_export_keeps_its_original_shape(): void
    {
        $this->seedSales();
        $manager = User::factory()->manager()->create();

        $response = $this->actingAs($manager)->get('/reports/sales/daily?format=xlsx')->assertOk();

        $this->assertMatchesRegularExpression('/^daily-sales-summary-\d{8}-\d{6}\.(xlsx|csv)$/', basename((string) preg_replace('/^.*filename="?([^";]+)"?.*$/', '$1', (string) $response->headers->get('content-disposition'))));
    }
}
