<?php

namespace Tests\Feature\Debts;

use App\Models\CustomerPayment;
use App\Models\Setting;
use App\Models\Shift;
use App\Services\ShiftService;

/**
 * Cash repayments are drawer money, in the shift that took them and in the currency they came in; revenue and
 * the payment-method report never see a repayment; a closed shift keeps what it stored.
 */
class DebtShiftAndReportsTest extends DebtBookTestCase
{
    private function totals(?Shift $shift = null): array
    {
        return app(ShiftService::class)->totals(($shift ?? $this->shift)->fresh());
    }

    public function test_cash_repayments_raise_the_expected_drawer_in_their_own_currency_and_other_methods_do_not(): void
    {
        $this->creditSale(total: 500);
        $before = $this->totals();

        $this->repay(40);                                              // cash USD
        $this->repay(1800000, ['currency' => 'LBP']);                 // cash LBP (= $20)
        $this->repay(10, ['method' => 'card']);
        $this->repay(10, ['method' => 'wish']);
        $this->repay(10, ['method' => 'bank_transfer']);
        $after = $this->totals();

        $this->assertEquals($before['expected_usd'] + 40, $after['expected_usd']);
        $this->assertEquals($before['expected_lbp'] + 1800000, $after['expected_lbp']);
        $this->assertEquals(40, $after['debt_cash_usd']);
        $this->assertEquals(1800000, $after['debt_cash_lbp']);
        $this->assertEquals($before['total_revenue_usd'], $after['total_revenue_usd'], 'a repayment is not revenue');
        $this->assertSame(5, CustomerPayment::count());
    }

    public function test_a_voided_repayment_leaves_the_drawer_count(): void
    {
        $this->creditSale(total: 100);
        $before = $this->totals()['expected_usd'];
        $p = $this->repay(30);
        $this->assertEquals($before + 30, $this->totals()['expected_usd']);

        $this->ledger()->voidPayment($p, $this->manager, 'mistake');

        $this->assertEquals($before, $this->totals()['expected_usd']);
    }

    public function test_a_repayment_belongs_to_the_shift_that_took_it_and_a_closed_shift_keeps_its_stored_figures(): void
    {
        $this->creditSale(total: 100);
        $first = $this->shift;
        $this->repay(25);

        // Close the first shift the way the screen does, then open a second one for the same cashier.
        $this->actingAs($this->cashier)->post('/shifts/close', ['closing_cash_usd' => 75, 'closing_cash_lbp' => 0])->assertSessionHasNoErrors();
        $first->refresh();
        $this->assertEquals(75, $first->expected_cash_usd, 'opening 50 + the $25 repayment');
        $stored = [$first->expected_cash_usd, $first->expected_cash_lbp, $first->variance_usd];

        $second = Shift::create(['user_id' => $this->cashier->id, 'opened_at' => now(), 'opening_cash_usd' => 0, 'opening_cash_lbp' => 0, 'status' => Shift::STATUS_OPEN]);
        $p2 = $this->repay(10);
        $this->assertSame($second->id, $p2->shift_id);
        $this->assertEquals(10, $this->totals($second)['expected_usd']);

        // Voiding the payment taken in the closed shift (a manager, days later) changes no stored figure of that shift.
        $this->ledger()->voidPayment(CustomerPayment::where('shift_id', $first->id)->sole(), $this->manager, 'refunded by hand');
        $first->refresh();
        $this->assertSame($stored, [$first->expected_cash_usd, $first->expected_cash_lbp, $first->variance_usd]);
        $this->assertEquals(10, $this->totals($second)['expected_usd'], 'and the second shift is untouched');
    }

    public function test_the_close_screen_lists_the_repayments_by_method_read_only(): void
    {
        $this->creditSale(total: 200);
        $this->repay(40);
        $this->repay(25, ['method' => 'card']);
        $this->repay(900000, ['currency' => 'LBP']);

        $res = $this->actingAs($this->cashier)->get('/shifts/close')->assertOk();

        $res->assertSee(__('debts.shift_title'))->assertSee('$40.00')->assertSee('$25.00')->assertSee('900,000');
        $res->assertSee(__('enums.debt_method.card'));
    }

    public function test_the_close_screen_has_no_debt_panel_without_repayments(): void
    {
        $this->creditSale(total: 100);

        $this->actingAs($this->cashier)->get('/shifts/close')->assertOk()->assertDontSee(__('debts.shift_title'));
    }

    public function test_repayments_are_not_revenue_and_not_in_the_payment_method_section_but_have_their_own(): void
    {
        $this->creditSale(total: 100);
        $this->repay(60);
        $this->repay(10, ['method' => 'card']);
        $range = ['from' => now()->subDay()->toDateString(), 'to' => now()->toDateString()];

        $svc = app(\App\Services\ReportService::class);
        $daily = $svc->dailySales(now()->subDay()->startOfDay(), now()->endOfDay());
        $breakdown = $svc->dailySalesBreakdown(now()->subDay()->startOfDay(), now()->endOfDay());

        $this->assertEquals(100, $daily['totals']['total'], 'revenue is the sale, on the day it was made');
        $this->assertEquals(100, $breakdown['revenue']);
        $byMethod = collect($breakdown['payments'])->pluck('usd', 'method');
        $this->assertEquals(100, $byMethod['credit'], 'the sale on account');
        $this->assertEquals(0, $byMethod['cash'], 'a repayment is not cash revenue');
        $this->assertEquals(0, $byMethod['card']);

        $collected = $svc->debtCollected(now()->subDay()->startOfDay(), now()->endOfDay());
        $this->assertEquals(70, $collected['total']);
        $this->assertSame(['card', 'cash'], array_column($collected['rows'], 'method'));

        $html = $this->actingAs($this->manager)->get(route('reports.daily-sales', $range))->assertOk()->getContent();
        $this->assertStringContainsString(__('debts.collected_title'), $html);
        $this->assertStringContainsString('$70.00', $html);

        // A voided repayment is not collected, and a write-off never was.
        $this->ledger()->voidPayment(CustomerPayment::where('method', 'card')->sole(), $this->manager, 'x');
        $this->ledger()->writeOff($this->customer, ['amount_usd' => 5, 'reason' => 'goodwill'], $this->manager);
        $this->assertEquals(60, $svc->debtCollected(now()->subDay()->startOfDay(), now()->endOfDay())['total']);

        $pdf = $this->actingAs($this->manager)->get(route('reports.daily-sales', $range + ['format' => 'pdf']))->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
    }

    public function test_the_daily_sales_page_has_no_collected_section_when_nothing_was_collected(): void
    {
        $this->creditSale(total: 100);

        $this->actingAs($this->manager)->get(route('reports.daily-sales'))->assertOk()->assertDontSee(__('debts.collected_title'));
    }

    public function test_a_backdated_repayment_lands_on_its_own_day_in_the_report_but_in_todays_drawer(): void
    {
        $this->creditSale(total: 100);
        $yesterday = now()->subDay();
        $before = $this->totals()['expected_usd'];

        $this->repay(20, ['paid_at' => $yesterday->toDateString(), 'method' => 'cash'], $this->managerWithShift());

        $svc = app(\App\Services\ReportService::class);
        $this->assertEquals(20, $svc->debtCollected($yesterday->copy()->startOfDay(), $yesterday->copy()->endOfDay())['total']);
        $this->assertEquals(0, $svc->debtCollected(now()->startOfDay(), now()->endOfDay())['total']);
        $this->assertEquals($before, $this->totals()['expected_usd'], 'it went into the manager\'s drawer, not the cashier\'s');
    }

    private function managerWithShift(): \App\Models\User
    {
        Shift::create(['user_id' => $this->manager->id, 'opened_at' => now(), 'opening_cash_usd' => 0, 'opening_cash_lbp' => 0, 'status' => Shift::STATUS_OPEN]);

        return $this->manager;
    }

    public function test_cash_repayment_needs_a_shift_only_while_shifts_are_required(): void
    {
        $this->creditSale(total: 100);
        $this->shift->update(['status' => Shift::STATUS_CLOSED, 'closed_at' => now()]);
        Setting::set('require_shift', false, 'pos', 'bool');

        $p = $this->repay(10);

        $this->assertNull($p->shift_id);
        $this->assertSame(0, CustomerPayment::drawerCash()->where('shift_id', $this->shift->id)->count());
    }
}
