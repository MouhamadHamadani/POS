<?php

namespace Tests\Feature\Sales;

use App\Facades\Feature;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\User;
use App\Services\SaleHistoryService;
use App\Services\SaleService;
use App\Services\ShiftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Payments are splittable across cash (USD / LBP), card, Wish and on-account, for on-site and online sales.
 * The legacy columns on `sales` stay the drawer ledger; `sale_payments` is the itemised record; the server
 * derives payment_method; and anything that is not cash can never cause change.
 */
class SplitPaymentsTest extends TestCase
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
            'opening_cash_usd' => 50, 'opening_cash_lbp' => 0, 'status' => Shift::STATUS_OPEN,
        ]);
        $this->product = Product::factory()->create(['price_usd' => 10, 'cost_usd' => 4, 'stock_qty' => 100, 'is_taxable' => false]);
    }

    private function pay(array $payment, array $extra = [], ?Product $product = null)
    {
        $product ??= $this->product;

        return $this->actingAs($this->cashier)->postJson('/pos/api/sales', $extra + [
            'cart' => [['product_id' => $product->id, 'qty' => 1]],
            'payment' => $payment,
        ]);
    }

    private function totals(): array
    {
        return app(ShiftService::class)->totals($this->shift->fresh());
    }

    private function lines(Sale $sale): array
    {
        return $sale->payments()->orderBy('id')->get()->map(fn ($p) => [$p->method, $p->currency, (float) $p->amount, (float) $p->amount_usd, $p->reference])->all();
    }

    // --- Cash + Wish, on-site and online --------------------------------------------------

    public function test_a_cash_and_wish_split_completes_and_is_recorded_line_by_line(): void
    {
        $this->pay(['amount_usd' => 10, 'amount_wish' => 4, 'wish_reference' => 'W-123'])
            ->assertCreated()->assertJsonPath('sale.payment_method', 'split');

        $sale = Sale::sole();
        $this->assertSame('split', $sale->payment_method);
        // The drawer ledger: $10 tendered against the $6 left for cash, so $4 change.
        $this->assertEquals(10, $sale->amount_tendered_usd);
        $this->assertEquals(4, $sale->change_usd);
        $this->assertEquals(0, $sale->amount_card_usd);
        $this->assertSame([['cash', 'USD', 10.0, 10.0, null], ['wish', 'USD', 4.0, 4.0, 'W-123']], $this->lines($sale));
        $this->assertEquals(99, $this->product->fresh()->stock_qty, 'a split sale deducts stock like any other');
    }

    public function test_the_same_split_works_for_an_online_order(): void
    {
        $this->pay(['amount_usd' => 6, 'amount_wish' => 4], ['channel' => 'online', 'channel_source' => 'instagram'])->assertCreated();

        $sale = Sale::sole();
        $this->assertSame('online', $sale->channel);
        $this->assertSame('split', $sale->payment_method);
        $this->assertEquals(0, $sale->change_usd);
        $this->assertCount(2, $sale->payments);
    }

    public function test_a_full_wish_payment_has_no_cash_and_no_change(): void
    {
        $this->pay(['amount_wish' => 10, 'wish_reference' => 'W-FULL'])->assertCreated()->assertJsonPath('sale.payment_method', 'wish');

        $sale = Sale::sole();
        $this->assertNull($sale->amount_tendered_usd);
        $this->assertEquals(0, $sale->change_usd);
        $this->assertSame([['wish', 'USD', 10.0, 10.0, 'W-FULL']], $this->lines($sale));
    }

    // --- The server derives payment_method ----------------------------------------------------

    public function test_payment_method_is_derived_from_the_amounts_and_the_clients_method_is_ignored(): void
    {
        $cases = [
            'cash_usd' => [['amount_usd' => 10], 'cash_usd'],
            'cash_lbp' => [['amount_lbp' => 900000], 'cash_lbp'],
            'mixed' => [['amount_usd' => 5, 'amount_lbp' => 450000], 'mixed'],
            'card' => [['amount_card' => 10], 'card'],
            'wish' => [['amount_wish' => 10], 'wish'],
            'cash+card' => [['amount_usd' => 5, 'amount_card' => 5], 'split'],
            'card+wish' => [['amount_card' => 3, 'amount_wish' => 7], 'split'],
            'usd+lbp+wish' => [['amount_usd' => 3, 'amount_lbp' => 270000, 'amount_wish' => 4], 'split'],
        ];

        foreach ($cases as $name => [$payment, $expected]) {
            $this->pay($payment + ['method' => 'cash_usd'])->assertCreated();
            $this->assertSame($expected, Sale::latest('id')->first()->payment_method, $name);
        }

        // Claiming a method the amounts do not support changes nothing.
        $this->pay(['method' => 'card', 'amount_usd' => 10])->assertCreated();
        $this->assertSame('cash_usd', Sale::latest('id')->first()->payment_method);
        $this->pay(['amount_usd' => 10])->assertCreated(); // and no method at all is fine
    }

    public function test_an_account_sale_is_derived_as_credit_and_a_mixed_one_with_cash_as_split(): void
    {
        $customer = Customer::create(['name' => 'Rami', 'customer_group' => 'retail', 'is_active' => true, 'credit_limit' => 100]);

        $this->pay(['amount_credit' => 10], ['customer_id' => $customer->id])->assertCreated();
        $this->assertSame('credit', Sale::latest('id')->first()->payment_method);
        $this->assertEquals(10, $customer->fresh()->balance);

        $this->pay(['amount_credit' => 4, 'amount_wish' => 2, 'amount_usd' => 4], ['customer_id' => $customer->id])->assertCreated();
        $this->assertSame('split', Sale::latest('id')->first()->payment_method);
    }

    // --- Non-cash can never cause change -------------------------------------------------------

    public function test_card_wish_or_account_over_the_total_is_refused_and_nothing_is_recorded(): void
    {
        foreach ([['amount_card' => 12], ['amount_wish' => 11], ['amount_card' => 6, 'amount_wish' => 6]] as $payment) {
            $this->pay($payment)->assertStatus(422)
                ->assertJsonPath('error.payment.0', __('messages.payment_noncash_exceeds'));
        }

        $customer = Customer::create(['name' => 'Rami', 'customer_group' => 'retail', 'is_active' => true, 'credit_limit' => 100]);
        $this->pay(['amount_credit' => 7, 'amount_card' => 7], ['customer_id' => $customer->id])->assertStatus(422);

        $this->assertSame(0, Sale::count());
        $this->assertSame(0, SalePayment::count());
        $this->assertEquals(100, $this->product->fresh()->stock_qty);
        $this->assertEquals(50.0, $this->totals()['expected_usd'], 'nothing reached the drawer');
    }

    public function test_the_old_card_overpayment_that_handed_back_drawer_cash_is_gone(): void
    {
        // Card $15 on a $10 sale used to ask for the difference as drawer change when cash was also given.
        $this->pay(['amount_card' => 15, 'amount_usd' => 5])->assertStatus(422);
        $this->assertSame(0, Sale::count());
    }

    public function test_cash_is_refused_when_nothing_is_left_for_it_to_cover(): void
    {
        $this->pay(['amount_card' => 10, 'amount_usd' => 5])->assertStatus(422)
            ->assertJsonPath('error.payment.0', __('messages.payment_cash_not_needed'));
        $this->pay(['amount_wish' => 6, 'amount_card' => 4, 'amount_lbp' => 90000])->assertStatus(422)
            ->assertJsonPath('error.payment.0', __('messages.payment_cash_not_needed'));

        $this->pay(['amount_card' => 10, 'amount_usd' => 0, 'amount_lbp' => 0])->assertCreated();
        $this->assertSame(1, Sale::count());
    }

    public function test_an_underpayment_across_methods_is_still_refused(): void
    {
        $this->pay(['amount_usd' => 3, 'amount_wish' => 4])->assertStatus(422)
            ->assertJsonPath('error.payment.0', __('messages.payment_insufficient'));
        $this->assertSame(0, Sale::count());
    }

    public function test_over_tendered_cash_still_gives_change_against_what_cash_owes(): void
    {
        $this->pay(['amount_usd' => 20, 'amount_card' => 4])->assertCreated();

        $sale = Sale::sole();
        $this->assertEquals(14, $sale->change_usd, '$20 tendered against the $6 cash owes');
        $this->assertEquals(0, $sale->change_lbp);
    }

    // --- Rounding ------------------------------------------------------------------------------

    public function test_an_lbp_rounded_split_adds_up_to_the_total_within_the_rounding_step(): void
    {
        Setting::set('exchange_rate', 89500, 'currency', 'float');
        Setting::set('lbp_rounding_step', 1000, 'currency', 'float');
        $odd = Product::factory()->create(['price_usd' => 7.77, 'cost_usd' => 3, 'stock_qty' => 10, 'is_taxable' => false]);

        // Wish $3.33 leaves $4.44 for cash; the customer hands over 400,000 LBP and takes the change in LBP.
        $this->pay(['amount_lbp' => 400000, 'amount_wish' => 3.33, 'change_usd_out' => 0], [], $odd)->assertCreated();

        $sale = Sale::sole();
        $rate = (float) $sale->exchange_rate;
        $stepUsd = 1000 / $rate;
        $wishUsd = (float) SalePayment::where('method', 'wish')->value('amount_usd');
        $cashNetUsd = (float) $sale->amount_tendered_lbp / $rate - (float) $sale->change_lbp / $rate - (float) $sale->change_usd;

        $this->assertGreaterThan(0, (float) $sale->change_lbp, 'the change really was rounded in LBP');
        $this->assertEqualsWithDelta((float) $sale->total_usd, $wishUsd + $cashNetUsd, $stepUsd, 'non-cash + cash tendered - change = total, within one LBP step');
    }

    // --- The drawer ------------------------------------------------------------------------------

    public function test_wish_and_card_never_change_the_expected_drawer_cash(): void
    {
        $before = $this->totals();

        $this->pay(['amount_wish' => 10])->assertCreated();
        $this->pay(['amount_card' => 10])->assertCreated();

        $after = $this->totals();
        $this->assertEquals($before['expected_usd'], $after['expected_usd']);
        $this->assertEquals($before['expected_lbp'], $after['expected_lbp']);
        $this->assertEquals(0, $after['cash_in_usd']);
        $this->assertSame(2, $after['sales_count'], 'they are still sales of the shift');
        $this->assertEquals(20, $after['total_revenue_usd']);
    }

    public function test_only_the_cash_part_of_a_split_counts_in_the_drawer(): void
    {
        $this->pay(['amount_usd' => 10, 'amount_wish' => 4])->assertCreated(); // $6 of cash kept: $10 in, $4 back
        $this->pay(['amount_usd' => 5, 'amount_card' => 5])->assertCreated();   // $5 of cash kept

        $this->assertEquals(50 + 6 + 5, $this->totals()['expected_usd']);
    }

    public function test_the_close_screen_shows_card_and_wish_takings_apart_from_the_drawer_count(): void
    {
        $this->pay(['amount_wish' => 10, 'wish_reference' => 'W-A'])->assertCreated();
        $this->pay(['amount_card' => 4, 'amount_usd' => 6])->assertCreated();
        $this->pay(['amount_usd' => 10])->assertCreated();

        $nonCash = app(ShiftService::class)->nonCashTotals($this->shift);
        $this->assertSame(['card_usd' => 4.0, 'wish_usd' => 10.0], $nonCash);

        $page = $this->actingAs($this->cashier)->get('/shifts/close')->assertOk()
            ->assertSee(__('shifts.noncash_title'))->assertSee('$4.00', false)->assertSee('$10.00', false);
        $this->assertEquals(50 + 6 + 10, $this->totals()['expected_usd']);
        $page->assertSee('$66.00', false); // the expected drawer figure is untouched by the panel
    }

    public function test_the_close_screen_has_no_noncash_panel_when_there_were_none(): void
    {
        $this->pay(['amount_usd' => 10])->assertCreated();

        $this->actingAs($this->cashier)->get('/shifts/close')->assertOk()->assertDontSee(__('shifts.noncash_title'));
    }

    // --- Audit, void, hold ------------------------------------------------------------------------

    public function test_the_audit_entry_carries_the_method_and_the_per_method_amounts(): void
    {
        $this->pay(['amount_usd' => 6, 'amount_wish' => 4], ['channel' => 'online'])->assertCreated();

        $log = AuditLog::where('action', 'sale')->latest('id')->first();
        $this->assertSame('split', $log->new_values['payment_method']);
        $this->assertSame(['cash_usd' => 6, 'wish' => 4], $log->new_values['payments']);
        $this->assertSame('online', $log->new_values['channel']);
    }

    public function test_a_split_sale_can_be_voided_and_keeps_its_payment_lines(): void
    {
        $this->pay(['amount_usd' => 6, 'amount_wish' => 4])->assertCreated();
        $sale = Sale::sole();

        app(SaleService::class)->voidSale($sale, $this->cashier->id, 'mistake');

        $this->assertSame(Sale::STATUS_VOIDED, $sale->fresh()->status);
        $this->assertCount(2, $sale->fresh()->payments);
        $this->assertEquals(100, $this->product->fresh()->stock_qty);
        $this->assertEquals(50.0, $this->totals()['expected_usd'], 'a voided sale is out of the drawer');
    }

    // --- Helpers ------------------------------------------------------------------------------------

    public function test_deriving_a_method_for_a_sale_with_nothing_tendered_defaults_to_cash_usd(): void
    {
        $this->assertSame('cash_usd', Sale::deriveMethod(0, 0, 0, 0, 0));
        $this->assertSame('split', Sale::deriveMethod(1, 0, 1, 0, 0));
        $this->assertSame('mixed', Sale::deriveMethod(1, 1, 0, 0, 0));
        $this->assertSame('wish', Sale::deriveMethod(0, 0, 0, 1, 0));
    }

    public function test_a_sale_built_from_the_flat_columns_alone_still_shows_its_lines(): void
    {
        $sale = Sale::create([
            'receipt_number' => 'LEG-1', 'user_id' => $this->cashier->id, 'shift_id' => $this->shift->id,
            'subtotal_usd' => 10, 'total_usd' => 10, 'exchange_rate' => 90000, 'payment_method' => 'card',
            'amount_tendered_usd' => 5, 'amount_tendered_lbp' => 450000, 'amount_card_usd' => 3, 'amount_credit_usd' => 2,
            'card_type' => 'Visa', 'card_reference' => 'AP-9', 'status' => Sale::STATUS_COMPLETED,
        ]);

        $lines = $sale->paymentLines();
        $this->assertSame(['cash_usd', 'cash_lbp', 'card', 'credit'], $lines->pluck('kind')->all());
        $this->assertEquals(5.0, $lines[1]['amount_usd'], '450,000 LBP at 90,000');
        $this->assertSame('AP-9', $lines[2]['reference']);
        $this->assertSame(0, SalePayment::count(), 'display only: nothing was written');
    }

    // --- Sales History: the payment filter ----------------------------------------------------------------

    public function test_the_history_payment_filter_matches_a_split_sale_under_every_method_it_contains(): void
    {
        $manager = User::factory()->manager()->create();
        $customer = Customer::create(['name' => 'Rami', 'customer_group' => 'retail', 'is_active' => true, 'credit_limit' => 100]);

        $this->pay(['amount_usd' => 10])->assertCreated();                                   // cash
        $this->pay(['amount_card' => 10])->assertCreated();                                  // card
        $this->pay(['amount_wish' => 10])->assertCreated();                                  // wish
        $this->pay(['amount_credit' => 10], ['customer_id' => $customer->id])->assertCreated(); // on account
        $this->pay(['amount_usd' => 6, 'amount_wish' => 4])->assertCreated();                // cash + wish
        // A sale from before sale_payments: legacy columns only, no lines.
        Sale::create([
            'receipt_number' => 'LEG-CARD', 'user_id' => $this->cashier->id, 'shift_id' => $this->shift->id,
            'subtotal_usd' => 8, 'total_usd' => 8, 'exchange_rate' => 90000, 'payment_method' => 'card',
            'amount_card_usd' => 8, 'status' => Sale::STATUS_COMPLETED,
        ]);
        Sale::create([
            'receipt_number' => 'LEG-CASH', 'user_id' => $this->cashier->id, 'shift_id' => $this->shift->id,
            'subtotal_usd' => 8, 'total_usd' => 8, 'exchange_rate' => 90000, 'payment_method' => 'cash_usd',
            'amount_tendered_usd' => 8, 'status' => Sale::STATUS_COMPLETED,
        ]);

        $numbers = fn (string $filter) => app(SaleHistoryService::class)
            ->query(app(SaleHistoryService::class)->filters(['payment_method' => $filter, 'from' => now()->toDateString()]), $manager)
            ->orderBy('sales.id')->pluck('receipt_number')->all();
        $all = Sale::orderBy('id')->pluck('receipt_number', 'id');

        $this->assertCount(7, $all);
        // cash: the cash sale, the cash+wish split, the legacy cash sale
        $this->assertSame([$all[1], $all[5], $all[7]], $numbers('cash'));
        // card: the card sale and the legacy card sale (which has no lines)
        $this->assertSame([$all[2], $all[6]], $numbers('card'));
        $this->assertSame([$all[3], $all[5]], $numbers('wish'));
        $this->assertSame([$all[4]], $numbers('credit'));
        $this->assertSame([$all[5]], $numbers('split'));
        // stored values from old bookmarks still filter, exactly
        $this->assertSame([$all[1], $all[7]], $numbers('cash_usd'));

        $this->actingAs($manager)->get('/sales?payment_method=wish&from='.now()->toDateString())->assertOk()
            ->assertSee('/sales/3?', false)->assertSee('/sales/5?', false)->assertDontSee('/sales/1?', false);
        $this->actingAs($manager)->get('/sales?payment_method=bogus')->assertSessionHasErrors('payment_method');
    }

    // --- The backfill --------------------------------------------------------------------------------------

    public function test_the_backfill_gives_every_old_sale_its_lines_and_is_idempotent(): void
    {
        $make = fn (string $number, array $attrs) => Sale::create($attrs + [
            'receipt_number' => $number, 'user_id' => $this->cashier->id, 'shift_id' => $this->shift->id,
            'subtotal_usd' => 10, 'total_usd' => 10, 'exchange_rate' => 90000, 'payment_method' => 'cash_usd',
            'status' => Sale::STATUS_COMPLETED,
        ]);
        $cash = $make('B-1', ['amount_tendered_usd' => 10]);
        $mixed = $make('B-2', ['payment_method' => 'mixed', 'amount_tendered_usd' => 5, 'amount_tendered_lbp' => 450000]);
        $card = $make('B-3', ['payment_method' => 'card', 'amount_card_usd' => 10, 'card_type' => 'Visa', 'card_reference' => 'AP-1']);
        $voided = $make('B-4', ['payment_method' => 'credit', 'amount_credit_usd' => 10, 'status' => Sale::STATUS_VOIDED]);
        $new = $make('B-5', ['amount_tendered_usd' => 10]);
        $new->payments()->create(['method' => 'cash', 'currency' => 'USD', 'amount' => 10, 'amount_usd' => 10]); // already has its line

        $run = fn () => (require database_path('migrations/2026_10_22_000004_backfill_sale_payments.php'))->up();
        $run();

        $this->assertSame([['cash', 'USD', 10.0, 10.0, null]], $this->lines($cash));
        $this->assertSame([['cash', 'USD', 5.0, 5.0, null], ['cash', 'LBP', 450000.0, 5.0, null]], $this->lines($mixed));
        $this->assertSame([['card', 'USD', 10.0, 10.0, 'AP-1']], $this->lines($card));
        $this->assertSame('Visa', $card->payments()->value('card_type'));
        $this->assertSame([['credit', 'USD', 10.0, 10.0, null]], $this->lines($voided), 'voided sales get lines too');
        $this->assertCount(1, $new->payments, 'a sale that already had a line is left alone');

        $count = SalePayment::count();
        $run();
        $this->assertSame($count, SalePayment::count(), 'a second run writes nothing');
    }

    // --- Receipts and the detail page -----------------------------------------------------------------------

    public function test_every_payment_line_prints_on_the_receipts_and_the_detail_page(): void
    {
        $manager = User::factory()->manager()->create();
        $this->pay(['amount_usd' => 10, 'amount_wish' => 4, 'wish_reference' => 'WISH-7788'], ['channel' => 'online', 'channel_source' => 'whatsapp'])->assertCreated();
        $this->pay(['amount_card' => 3, 'card_reference' => 'APPR-4455', 'card_type' => 'Visa', 'amount_usd' => 7])->assertCreated();
        [$wish, $card] = [Sale::orderBy('id')->first(), Sale::orderBy('id')->get()[1]];

        foreach (['thermal', 'a4'] as $format) {
            $this->actingAs($manager)->get(route('receipts.print', ['sale' => $wish, 'format' => $format, 'auto' => 0]))
                ->assertOk()->assertSee('WISH-7788')->assertSee('Wish')->assertSee('ويش')->assertSee('$4.00', false)->assertSee('$10.00', false)
                ->assertSee('Split')->assertSee('Online order');
            $this->actingAs($manager)->get(route('receipts.print', ['sale' => $card, 'format' => $format, 'auto' => 0]))
                ->assertOk()->assertSee('APPR-4455')->assertSee('Card')->assertSee('$3.00', false);
        }

        $this->actingAs($manager)->get(route('sales.show', $wish))->assertOk()
            ->assertSee(__('receipt.wish'))->assertSee('WISH-7788')->assertSee(__('receipt.cash_usd_tendered'))
            ->assertSee(__('enums.payment_method.split'));
        $this->actingAs($manager)->get(route('sales.show', $card))->assertOk()->assertSee('APPR-4455')->assertSee('Visa');
    }

    public function test_the_custom_template_gets_a_payments_block(): void
    {
        $this->pay(['amount_usd' => 10, 'amount_wish' => 4, 'wish_reference' => 'W-99'])->assertCreated();
        $sale = Sale::sole()->load('items');
        $template = new \App\Models\PrintTemplate(['type' => 'receipt', 'template_html' => '{{#payments}}[{{method}}|{{amount}}|{{reference}}]{{/payments}}']);

        $html = \App\Services\ReceiptRenderer::render($template, $sale, ['business' => []]);

        $this->assertStringContainsString('[Cash (USD)|10.00|]', $html);
        $this->assertStringContainsString('[Wish|4.00|W-99]', $html);
    }

    public function test_the_test_print_is_a_cash_and_wish_split_with_an_online_line(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post('/settings/receipt/test-print', ['settings' => ['receipt_width' => 80, 'receipt_language' => 'en']])
            ->assertOk()->assertSee('WISH-TEST-001')->assertSee('Wish')->assertSee('Online order')->assertSee('WhatsApp')->assertSee('Split');
    }

    // --- Returns ---------------------------------------------------------------------------------------------

    private function returnTo(Sale $sale, string $method)
    {
        $line = $sale->items()->first();

        return $this->actingAs(User::factory()->manager()->create())->post(route('returns.store', $sale), [
            'items' => [['sale_item_id' => $line->id, 'qty_returned' => 1, 'restock' => 1]],
            'reason' => 'defective', 'refund_method' => $method,
        ]);
    }

    public function test_card_and_wish_refunds_are_accepted_and_move_no_drawer_cash(): void
    {
        foreach (['card', 'wish'] as $method) {
            $this->pay([$method === 'card' ? 'amount_card' : 'amount_wish' => 10])->assertCreated();
            $sale = Sale::latest('id')->first();
            $before = $this->totals();

            $this->returnTo($sale, $method)->assertSessionHasNoErrors();

            $this->assertSame($method, \App\Models\ReturnTransaction::latest('id')->first()->refund_method);
            $after = $this->totals();
            $this->assertEquals($before['expected_usd'], $after['expected_usd'], "{$method} refund moved drawer cash");
            $this->assertEquals(0, $after['cash_out_usd']);
        }

        $this->pay(['amount_usd' => 10])->assertCreated();
        $this->returnTo(Sale::latest('id')->first(), 'bitcoin')->assertSessionHasErrors('refund_method');
    }

    public function test_the_return_form_preselects_the_method_of_the_largest_payment_line(): void
    {
        $manager = User::factory()->manager()->create();
        $customer = Customer::create(['name' => 'Rami', 'customer_group' => 'retail', 'is_active' => true, 'credit_limit' => 100]);

        $selected = function (Sale $sale) use ($manager) {
            $html = $this->actingAs($manager)->get(route('returns.create', $sale))->assertOk()->getContent();
            preg_match('/<option value="([a-z_]+)"\s+selected/', $html, $m);

            return $m[1] ?? null;
        };

        $this->pay(['amount_wish' => 7, 'amount_usd' => 3])->assertCreated();
        $this->assertSame('wish', $selected(Sale::latest('id')->first()));

        $this->pay(['amount_card' => 6, 'amount_usd' => 4])->assertCreated();
        $this->assertSame('card', $selected(Sale::latest('id')->first()));

        $this->pay(['amount_usd' => 7, 'amount_wish' => 3])->assertCreated();
        $this->assertSame('cash_usd', $selected(Sale::latest('id')->first()));

        $this->pay(['amount_credit' => 8, 'amount_usd' => 2], ['customer_id' => $customer->id])->assertCreated();
        $this->assertSame('account', $selected(Sale::latest('id')->first()));

        $this->pay(['amount_usd' => 10])->assertCreated();
        $this->assertSame('cash_usd', $selected(Sale::latest('id')->first()));
    }

    // --- Housekeeping --------------------------------------------------------------------------------------------

    public function test_reset_data_clears_the_payment_lines_before_their_sales(): void
    {
        $tables = (new \ReflectionClassConstant(\App\Console\Commands\ResetData::class, 'TRANSACTIONAL'))->getValue();

        $this->assertContains('sale_payments', $tables);
        $this->assertLessThan(array_search('sales', $tables, true), array_search('sale_payments', $tables, true));
    }

    public function test_split_payments_are_core_on_every_plan(): void
    {
        foreach (['starter', 'standard', 'premium'] as $plan) {
            Setting::set('tier', $plan, 'license');
            $this->pay(['amount_usd' => 6, 'amount_wish' => 4])->assertCreated();
        }

        $this->assertSame(3, Sale::where('payment_method', 'split')->count());
        $this->assertFalse(array_key_exists('split_payments', Feature::definitions()), 'not a feature key: no plan can switch it off');
    }
}
