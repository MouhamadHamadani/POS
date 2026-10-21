<?php

namespace Tests\Feature\Shifts;

use App\Models\AuditLog;
use App\Models\Expense;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\User;
use App\Services\ReportService;
use App\Services\SaleService;
use App\Services\ShiftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * BUG-032: with Settings > POS > "Require open shift to sell" off, selling has to work with no shift
 * at all. With it on, nothing changes. A shift-less sale is a normal sale everywhere it is read.
 */
class ShiftlessSalesTest extends TestCase
{
    use RefreshDatabase;

    private function requireShift(bool $on): void
    {
        Setting::set('require_shift', $on ? '1' : '0', 'pos', 'bool');
    }

    private function product(): Product
    {
        return Product::factory()->create(['price_usd' => 10, 'cost_usd' => 4, 'stock_qty' => 50, 'is_taxable' => false]);
    }

    private function openShift(User $user): Shift
    {
        return Shift::create([
            'user_id' => $user->id, 'opened_at' => now(),
            'opening_cash_usd' => 50, 'opening_cash_lbp' => 0, 'status' => Shift::STATUS_OPEN,
        ]);
    }

    private function sell(User $user, Product $product, float $qty = 1)
    {
        return $this->actingAs($user)->postJson(route('pos.api.sales'), [
            'cart' => [['product_id' => $product->id, 'qty' => $qty]],
            'payment' => ['method' => 'cash_usd', 'amount_usd' => 10 * $qty],
        ]);
    }

    /** A completed shift-less sale rung up by $user. */
    private function shiftlessSale(User $user, ?Product $product = null): Sale
    {
        $this->requireShift(false);
        $this->sell($user, $product ?? $this->product())->assertCreated();

        return Sale::sole();
    }

    // --- Selling ------------------------------------------------------------------------

    public function test_with_shifts_off_a_sale_goes_through_with_no_shift_and_says_so_on_the_record(): void
    {
        $this->requireShift(false);
        $cashier = User::factory()->create();
        $product = $this->product();

        $this->actingAs($cashier)->get(route('pos.sell'))->assertOk();
        $this->sell($cashier, $product)->assertCreated();

        $sale = Sale::sole();
        $this->assertNull($sale->shift_id);
        $this->assertSame(0, Shift::count(), 'no shift was invented for it');
        $this->assertEquals(49, $product->fresh()->stock_qty);
        $this->assertNotNull(AuditLog::where('action', 'sale')->where('model_id', $sale->id)->first());
    }

    public function test_with_shifts_required_a_cashier_without_a_shift_is_still_refused(): void
    {
        $this->requireShift(true);
        $cashier = User::factory()->create();

        $this->sell($cashier, $this->product())->assertStatus(409);
        $this->assertSame(0, Sale::count());
    }

    public function test_the_service_refuses_a_shiftless_sale_on_its_own_when_shifts_are_required(): void
    {
        $this->requireShift(true);
        $cashier = User::factory()->create();
        $product = $this->product();

        try {
            app(SaleService::class)->process(
                cart: [['product_id' => $product->id, 'qty' => 1]],
                payment: ['method' => 'cash_usd', 'amount_usd' => 10],
                user: $cashier,
                shiftId: null,
            );
            $this->fail('expected a refusal');
        } catch (RuntimeException $e) {
            $this->assertSame(__('messages.no_open_shift'), $e->getMessage());
        }

        $this->assertSame(0, Sale::count());
        $this->assertEquals(50, $product->fresh()->stock_qty, 'nothing was deducted');
    }

    public function test_the_service_accepts_null_when_shifts_are_off(): void
    {
        $this->requireShift(false);
        $cashier = User::factory()->create();
        $product = $this->product();

        $sale = app(SaleService::class)->process(
            cart: [['product_id' => $product->id, 'qty' => 1]],
            payment: ['method' => 'cash_usd', 'amount_usd' => 10],
            user: $cashier,
            shiftId: null,
        );

        $this->assertNull($sale->shift_id);
    }

    public function test_a_shift_a_cashier_opened_voluntarily_still_gets_the_sale_and_the_drawer_count(): void
    {
        $this->requireShift(false);
        $cashier = User::factory()->create();
        $shift = $this->openShift($cashier);

        $this->sell($cashier, $this->product(), 2)->assertCreated();

        $this->assertSame($shift->id, Sale::sole()->shift_id);
        $totals = app(ShiftService::class)->totals($shift);
        $this->assertSame(1, $totals['sales_count']);
        $this->assertEquals(70.0, $totals['expected_usd']); // $50 opening + $20 sale
    }

    public function test_a_closed_shift_id_is_still_refused_even_with_shifts_off(): void
    {
        $this->requireShift(false);
        $cashier = User::factory()->create();
        $closed = $this->openShift($cashier);
        $closed->update(['status' => Shift::STATUS_CLOSED, 'closed_at' => now()]);
        $product = $this->product();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(__('messages.shift_closed_sale'));

        app(SaleService::class)->process(
            cart: [['product_id' => $product->id, 'qty' => 1]],
            payment: ['method' => 'cash_usd', 'amount_usd' => 10],
            user: $cashier,
            shiftId: $closed->id,
        );
    }

    // --- A shift-less sale is a normal sale everywhere ----------------------------------

    public function test_a_shiftless_sale_can_be_voided(): void
    {
        $cashier = User::factory()->create();
        $product = $this->product();
        $sale = $this->shiftlessSale($cashier, $product);

        $voided = app(SaleService::class)->voidSale($sale, $cashier->id, 'changed mind');

        $this->assertSame(Sale::STATUS_VOIDED, $voided->status);
        $this->assertEquals(50, $product->fresh()->stock_qty, 'stock went back');
    }

    public function test_a_shiftless_sale_can_be_returned_and_a_cash_refund_moves_no_drawer_cash(): void
    {
        $manager = User::factory()->manager()->create();
        $product = $this->product();
        $sale = $this->shiftlessSale($manager, $product);
        $line = SaleItem::where('sale_id', $sale->id)->sole();

        // The manager now opens a shift of their own; the refund must not leak into it.
        $shift = $this->openShift($manager);

        $this->actingAs($manager)->post(route('returns.store', $sale), [
            'items' => [['sale_item_id' => $line->id, 'qty_returned' => 1, 'restock' => 1]],
            'reason' => 'defective', 'refund_method' => 'cash_usd',
        ])->assertSessionHasNoErrors();

        $this->assertSame(Sale::STATUS_REFUNDED, $sale->fresh()->status);
        $totals = app(ShiftService::class)->totals($shift);
        $this->assertEquals(50.0, $totals['expected_usd'], 'the unrelated shift keeps its opening cash and nothing else');
        $this->assertEquals(0.0, $totals['cash_out_usd']);
        $this->assertEquals(50, $product->fresh()->stock_qty);
    }

    public function test_a_shiftless_sale_shows_in_every_report_and_in_sales_history(): void
    {
        $manager = User::factory()->manager()->create();
        $sale = $this->shiftlessSale($manager);

        $daily = app(ReportService::class)->dailySales(now()->startOfDay(), now()->endOfDay());
        $this->assertSame(1, (int) $daily['totals']['txn_count']);
        $this->assertEqualsWithDelta(10.0, (float) $daily['totals']['total'], 0.0001);

        $pnl = app(ReportService::class)->profitLoss(now()->startOfDay(), now()->endOfDay());
        $this->assertSame(1, $pnl['txn_count']);
        $this->assertEqualsWithDelta(10.0, $pnl['gross_revenue'], 0.0001);
        $this->assertEqualsWithDelta(4.0, $pnl['cogs'], 0.0001);

        $this->actingAs($manager)->get('/reports/sales/daily')->assertOk()->assertSee('10.00');
        $this->actingAs($manager)->get('/dashboard')->assertOk();
        $this->actingAs($manager)->get('/sales')->assertOk()->assertSee($sale->receipt_number);
        $this->actingAs($manager)->get(route('sales.show', $sale))->assertOk()->assertSee($sale->receipt_number);
        $this->actingAs($manager)->get(route('receipts.print', ['sale' => $sale, 'copy' => 1, 'src' => 'history']))->assertOk();
        $this->actingAs($manager)->getJson(route('pos.api.sales.show', $sale))->assertOk();
    }

    // --- Expenses -------------------------------------------------------------------------

    public function test_an_expense_form_with_no_shift_offers_outside_only_and_saves_without_error(): void
    {
        $this->requireShift(false);
        $manager = User::factory()->manager()->create();
        $category = \App\Models\ExpenseCategory::where('name', 'Rent')->value('id');

        $this->actingAs($manager)->get('/expenses/create')
            ->assertOk()
            ->assertSee(__('expenses.drawer_needs_shift'))
            ->assertSee('value="drawer"', false)
            ->assertSee('disabled', false);

        $this->actingAs($manager)->post('/expenses', [
            'expense_category_id' => $category, 'date' => now()->format('Y-m-d'),
            'currency' => 'usd', 'amount' => 12.5, 'paid_from' => 'outside',
        ])->assertRedirect('/expenses')->assertSessionHasNoErrors();
        $this->assertSame(1, Expense::count());
        $this->assertNull(Expense::sole()->shift_id);

        // A crafted "drawer" post is still refused, with a message rather than a crash.
        $this->actingAs($manager)->post('/expenses', [
            'expense_category_id' => $category, 'date' => now()->format('Y-m-d'),
            'currency' => 'usd', 'amount' => 5, 'paid_from' => 'drawer',
        ])->assertSessionHasErrors('paid_from');
        $this->assertSame(1, Expense::count());
    }

    // --- The till's own chrome ----------------------------------------------------------------

    public function test_the_close_shift_button_is_hidden_only_when_shifts_are_off_and_none_is_open(): void
    {
        $cashier = User::factory()->create();
        $label = __('nav.close_shift');

        $this->requireShift(false);
        $this->actingAs($cashier)->get(route('pos.sell'))->assertOk()->assertDontSee($label);

        $this->openShift($cashier);
        $this->actingAs($cashier)->get(route('pos.sell'))->assertOk()->assertSee($label);

        $this->requireShift(true);
        $this->actingAs($cashier)->get(route('pos.sell'))->assertOk()->assertSee($label);
    }
}
