<?php

namespace Tests\Feature\Sales;

use App\Models\Product;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\Tax;
use App\Models\User;
use App\Services\ReportService;
use App\Services\ShiftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A sale's total carries four decimals (VAT is worked out per line), but the till shows it in cents and a customer
 * pays that on the card machine or in the Wish app. A payment within half a cent of the stored total is the total.
 */
class CentRoundedPaymentTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;
    private Shift $shift;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::set('exchange_rate', 90000, 'currency', 'float');
        $vat = Tax::create(['name' => 'VAT', 'rate' => 0.11, 'is_inclusive' => false, 'is_default' => true, 'is_active' => true]);
        $this->cashier = User::factory()->create();
        $this->shift = Shift::create([
            'user_id' => $this->cashier->id, 'opened_at' => now(),
            'opening_cash_usd' => 0, 'opening_cash_lbp' => 0, 'status' => Shift::STATUS_OPEN,
        ]);
        // $1.35 + 11% VAT = $1.4985: the till shows $1.50.
        $this->product = Product::factory()->create(['price_usd' => 1.35, 'cost_usd' => 0.5, 'stock_qty' => 100, 'is_taxable' => true, 'tax_id' => $vat->id]);
    }

    private function pay(array $payment)
    {
        return $this->actingAs($this->cashier)->postJson('/pos/api/sales', [
            'cart' => [['product_id' => $this->product->id, 'qty' => 1]],
            'payment' => $payment,
        ]);
    }

    public function test_the_totals_in_play_really_have_four_decimals(): void
    {
        $this->pay(['amount_card' => 1.50])->assertCreated();

        $this->assertEquals(1.4985, Sale::sole()->total_usd);
    }

    public function test_a_card_payment_rounded_to_the_cent_is_accepted(): void
    {
        // The till shows $1.50 for $1.4985, so that is what is charged: 0.0015 over the stored total.
        $this->pay(['amount_card' => 1.50])->assertCreated()->assertJsonPath('sale.payment_method', 'card');
    }

    public function test_the_same_goes_for_wish_and_for_card_plus_wish(): void
    {
        $this->pay(['amount_wish' => 1.50])->assertCreated()->assertJsonPath('sale.payment_method', 'wish');
        $this->pay(['amount_card' => 0.75, 'amount_wish' => 0.75])->assertCreated()->assertJsonPath('sale.payment_method', 'split');
    }

    public function test_more_than_half_a_cent_off_is_still_refused(): void
    {
        $this->pay(['amount_card' => 1.51])->assertStatus(422)->assertJsonPath('error.payment.0', __('messages.payment_noncash_exceeds'));
        $this->pay(['amount_card' => 1.49])->assertStatus(422)->assertJsonPath('error.payment.0', __('messages.payment_insufficient'));   // a whole cent short of the $1.50 shown
        $this->pay(['amount_card' => 1.48])->assertStatus(422)->assertJsonPath('error.payment.0', __('messages.payment_insufficient'));
        $this->assertSame(0, Sale::count());
    }

    public function test_the_lines_add_up_to_the_total_while_the_amount_stays_what_the_machine_charged(): void
    {
        $this->pay(['amount_card' => 1.50])->assertCreated();   // over by 0.0015
        $this->pay(['amount_wish' => 1.50])->assertCreated();

        foreach (Sale::all() as $sale) {
            $line = $sale->payments()->sole();
            $this->assertEquals(1.50, $line->amount, 'the machine / app was charged $1.50');
            $this->assertEquals(1.4985, $line->amount_usd, 'booked at what the sale came to');
            $this->assertEquals((float) $sale->total_usd, $sale->payments()->sum('amount_usd'));
        }
    }

    public function test_a_payment_a_fraction_short_is_booked_up_to_the_total_too(): void
    {
        // $1.49 for a $1.4985 sale is 0.0085 short: refused. 1.4949 would be within half a cent; use a total that is.
        $odd = Product::factory()->create(['price_usd' => 1.3468, 'cost_usd' => 0.5, 'stock_qty' => 10, 'is_taxable' => true, 'tax_id' => Tax::first()->id]);
        // 1.3468 * 1.11 = 1.494948 -> 1.4949 stored; $1.49 is 0.0049 short.
        $response = $this->actingAs($this->cashier)->postJson('/pos/api/sales', [
            'cart' => [['product_id' => $odd->id, 'qty' => 1]], 'payment' => ['amount_card' => 1.49],
        ])->assertCreated();

        $sale = Sale::find($response->json('sale.id'));
        $this->assertEquals(1.4949, $sale->total_usd);
        $this->assertEquals(1.49, $sale->payments()->sole()->amount);
        $this->assertEquals(1.4949, $sale->payments()->sole()->amount_usd);
    }

    public function test_the_reports_show_no_phantom_cash_and_the_shift_panel_adds_up_what_the_machine_charged(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->pay(['amount_card' => 1.50])->assertCreated();
        }

        $b = app(ReportService::class)->dailySalesBreakdown(now()->startOfDay(), now()->endOfDay());
        $by = collect($b['payments'])->keyBy('method');
        $this->assertEqualsWithDelta(0.0, $by['cash']['usd'], 0.00005, 'a day of card sales is not part cash');
        $this->assertEqualsWithDelta(4 * 1.4985, $by['card']['usd'], 0.00005);
        $this->assertEqualsWithDelta($b['revenue'], array_sum(array_column($b['payments'], 'usd')), 0.00005);

        // The panel is for ticking against the card machine: its own total, $6.00.
        $this->assertSame(['card_usd' => 6.0, 'wish_usd' => 0.0], app(ShiftService::class)->nonCashTotals($this->shift));
    }

    public function test_cash_is_unaffected(): void
    {
        $this->pay(['amount_usd' => 1.50])->assertCreated();

        $sale = Sale::sole();
        $this->assertEquals(1.50, $sale->amount_tendered_usd);
        $this->assertEquals(0.0015, $sale->change_usd);
        $this->assertEquals(1.50, SalePayment::sole()->amount_usd);
    }
}
