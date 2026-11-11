<?php

namespace Tests\Feature\Debts;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\Sale;
use App\Models\Setting;
use Carbon\Carbon;

/**
 * The /debts area: who may do what, the repayment form, the list, the customer page, the statement and the slip.
 */
class DebtScreensTest extends DebtBookTestCase
{
    private function payUrl(?Customer $c = null): string
    {
        return route('debts.payments.store', $c ?? $this->customer);
    }

    // --- Who may do what -------------------------------------------------------------------

    public function test_a_cashier_takes_a_repayment_but_cannot_touch_the_customer_admin_screens_void_or_write_off(): void
    {
        $sale = $this->creditSale(total: 100);

        $this->actingAs($this->cashier)->get(route('debts.index'))->assertOk()->assertSee('Nadia Khoury');
        $this->actingAs($this->cashier)->get(route('debts.show', $this->customer))->assertOk();
        $this->actingAs($this->cashier)->post($this->payUrl(), ['amount' => 30, 'currency' => 'USD', 'method' => 'cash'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(70.0, $this->owed());
        $payment = CustomerPayment::sole();
        $this->assertSame($this->shift->id, $payment->shift_id);

        // The customer-admin screens stay exactly as they were: admin and manager only.
        $this->actingAs($this->cashier)->get(route('customers.show', $this->customer))->assertForbidden();
        $this->actingAs($this->cashier)->get(route('customers.edit', $this->customer))->assertForbidden();
        $this->actingAs($this->cashier)->delete(route('customers.destroy', $this->customer))->assertForbidden();
        $this->assertNotNull($this->customer->fresh());

        // Manager-only actions.
        $this->actingAs($this->cashier)->post(route('debts.payments.void', $payment), ['reason' => 'x'])->assertForbidden();
        $this->actingAs($this->cashier)->post(route('debts.write-off', $this->customer), ['amount_usd' => 5, 'reason' => 'x'])->assertForbidden();
        $this->actingAs($this->cashier)->patch(route('debts.due-date', $sale), ['due_date' => now()->addDays(3)->toDateString()])->assertForbidden();
        $this->assertSame(70.0, $this->owed());
        $this->assertFalse($payment->fresh()->isVoided());
    }

    public function test_a_stock_user_has_no_access_to_the_debts_screens(): void
    {
        $stock = \App\Models\User::factory()->stock()->create();

        $this->actingAs($stock)->get(route('debts.index'))->assertForbidden();
        $this->actingAs($stock)->post($this->payUrl(), ['amount' => 1, 'currency' => 'USD', 'method' => 'cash'])->assertForbidden();
    }

    public function test_a_manager_voids_writes_off_and_moves_a_due_date_with_an_audit_trail(): void
    {
        $sale = $this->creditSale(total: 100);
        $this->actingAs($this->cashier)->post($this->payUrl(), ['amount' => 20, 'currency' => 'USD', 'method' => 'card'])->assertSessionHasNoErrors();
        $payment = CustomerPayment::sole();

        $this->actingAs($this->manager)->post(route('debts.payments.void', $payment), ['reason' => 'wrong customer'])->assertRedirect();
        $this->assertTrue($payment->fresh()->isVoided());
        $this->assertSame(100.0, $this->owed());

        $this->actingAs($this->manager)->post(route('debts.write-off', $this->customer), ['amount_usd' => 10, 'reason' => 'goodwill'])->assertSessionHasNoErrors();
        $this->assertSame(90.0, $this->owed());

        $due = now()->addDays(40)->toDateString();
        $this->actingAs($this->manager)->patch(route('debts.due-date', $sale), ['due_date' => $due])->assertSessionHasNoErrors();
        $this->assertSame($due, $sale->fresh()->due_date->toDateString());
        $this->assertSame($due, AuditLog::where('action', 'due_date_change')->sole()->new_values['due_date']);

        $this->actingAs($this->manager)->post(route('debts.write-off', $this->customer), ['amount_usd' => 5, 'reason' => ''])->assertSessionHasErrors('reason');
    }

    // --- The repayment form ----------------------------------------------------------------------

    public function test_the_form_applies_oldest_first_by_default_and_the_chosen_receipts_when_asked(): void
    {
        $late = $this->creditSale(total: 60, due: now()->addDays(20)->toDateString());
        $soon = $this->creditSale(total: 40, due: now()->addDays(5)->toDateString());

        $this->actingAs($this->cashier)->post($this->payUrl(), ['amount' => 50, 'currency' => 'USD', 'method' => 'cash', 'apply' => 'oldest'])->assertSessionHasNoErrors();
        $this->assertSame(0.0, $this->ledger()->outstandingForSale($soon));
        $this->assertSame(50.0, $this->ledger()->outstandingForSale($late));

        $this->actingAs($this->cashier)->post($this->payUrl(), [
            'amount' => 20, 'currency' => 'USD', 'method' => 'cash', 'apply' => 'choose',
            'allocations' => [['sale_id' => $late->id, 'amount_usd' => 20]],
        ])->assertSessionHasNoErrors();
        $this->assertSame(30.0, $this->ledger()->outstandingForSale($late));

        // Allocations that don't make up the payment go back to the form with the reason.
        $this->actingAs($this->cashier)->from($this->payUrl())->post($this->payUrl(), [
            'amount' => 20, 'currency' => 'USD', 'method' => 'cash', 'apply' => 'choose',
            'allocations' => [['sale_id' => $late->id, 'amount_usd' => 5]],
        ])->assertSessionHasErrors('allocations');
        $this->assertSame(30.0, $this->owed());
    }

    public function test_the_form_refuses_an_overpayment_and_takes_lbp_at_the_rate_of_the_day(): void
    {
        $this->creditSale(total: 100);

        $this->actingAs($this->cashier)->post($this->payUrl(), ['amount' => 100.5, 'currency' => 'USD', 'method' => 'cash'])->assertSessionHasErrors('amount_usd');
        $this->assertSame(100.0, $this->owed());

        $this->actingAs($this->cashier)->post($this->payUrl(), ['amount' => 2700000, 'currency' => 'LBP', 'method' => 'cash'])->assertSessionHasNoErrors();
        $p = CustomerPayment::sole();
        $this->assertEquals(30, $p->amount_usd);
        $this->assertEquals(90000, $p->exchange_rate);
        $this->assertSame(70.0, $this->owed());
    }

    public function test_a_cash_repayment_without_an_open_shift_is_refused_when_shifts_are_required_and_accepted_when_not(): void
    {
        $this->creditSale(total: 100);
        $this->shift->update(['status' => \App\Models\Shift::STATUS_CLOSED, 'closed_at' => now()]);

        $this->actingAs($this->cashier)->post($this->payUrl(), ['amount' => 10, 'currency' => 'USD', 'method' => 'cash'])->assertSessionHasErrors('method');
        $this->assertSame(0, CustomerPayment::count());

        $this->actingAs($this->cashier)->post($this->payUrl(), ['amount' => 10, 'currency' => 'USD', 'method' => 'bank_transfer'])->assertSessionHasNoErrors();
        $this->assertNull(CustomerPayment::latest('id')->first()->shift_id, 'recorded, but in no drawer');

        Setting::set('require_shift', false, 'pos', 'bool');
        $this->actingAs($this->cashier)->post($this->payUrl(), ['amount' => 10, 'currency' => 'USD', 'method' => 'cash'])->assertSessionHasNoErrors();
        $this->assertNull(CustomerPayment::latest('id')->first()->shift_id);
        $this->assertSame(80.0, $this->owed());
    }

    // --- The list and the customer page ------------------------------------------------------

    public function test_the_list_filters_by_overdue_and_search_and_exports(): void
    {
        $other = $this->customer(['name' => 'Rami Aoun', 'phone' => '71999888']);
        $this->creditSale(total: 100);
        $this->dueOn($this->creditSale($other, 40), Carbon::today()->subDays(10)->toDateString());

        $this->actingAs($this->cashier)->get(route('debts.index'))->assertSee('Nadia Khoury')->assertSee('Rami Aoun');
        $this->actingAs($this->cashier)->get(route('debts.index', ['overdue' => 1]))->assertSee('Rami Aoun')->assertDontSee('Nadia Khoury');
        $this->actingAs($this->cashier)->get(route('debts.index', ['q' => '0312']))->assertSee('Nadia Khoury')->assertDontSee('Rami Aoun');

        $this->actingAs($this->cashier)->get(route('debts.index', ['format' => 'xlsx']))->assertOk()->assertDownload();
        $pdf = $this->actingAs($this->cashier)->get(route('debts.index', ['format' => 'pdf']))->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
    }

    public function test_the_receivables_report_is_for_managers_and_shows_the_same_data(): void
    {
        $this->creditSale(total: 100);

        $this->actingAs($this->cashier)->get(route('reports.receivables'))->assertForbidden();
        $this->actingAs($this->manager)->get(route('reports.receivables'))->assertOk()->assertSee('Nadia Khoury');
        $this->actingAs($this->manager)->get(route('reports.index'))->assertSee(route('reports.receivables'));
    }

    public function test_the_customer_detail_shows_each_open_receipt_with_its_items_due_date_and_payments(): void
    {
        $sale = $this->creditSale(total: 100, due: now()->addDays(3)->toDateString());
        $this->dueOn($sale, Carbon::today()->subDays(4)->toDateString());
        $this->repay(25);

        $html = $this->actingAs($this->cashier)->get(route('debts.show', $this->customer))->assertOk()->getContent();

        $this->assertStringContainsString($sale->receipt_number, $html);
        $this->assertStringContainsString(Carbon::today()->subDays(4)->toDateString(), $html);
        $this->assertStringContainsString('4 days late', $html);
        $this->assertStringContainsString($this->product->name, $html, 'the items are in the expandable row');
        $this->assertStringContainsString(CustomerPayment::sole()->number, $html);
        $this->assertStringContainsString('$75.00', $html);

        // The customers page (admin/manager) carries the same tabs next to its own stats.
        $this->actingAs($this->manager)->get(route('customers.show', $this->customer))->assertOk()
            ->assertSee($sale->receipt_number)->assertSee(CustomerPayment::sole()->number);
    }

    public function test_a_cashier_does_not_see_the_items_of_a_sale_someone_else_rang_up(): void
    {
        $other = \App\Models\User::factory()->create();
        \App\Models\Shift::create(['user_id' => $other->id, 'opened_at' => now(), 'opening_cash_usd' => 0, 'opening_cash_lbp' => 0, 'status' => 'open']);
        $this->creditSale(total: 100, by: $other);

        $mine = $this->actingAs($this->cashier)->get(route('debts.show', $this->customer))->assertOk()->getContent();
        $this->assertStringContainsString(__('debts.items_hidden'), $mine);
        $this->assertStringNotContainsString($this->product->name, $mine);

        $this->assertStringContainsString($this->product->name, $this->actingAs($other)->get(route('debts.show', $this->customer))->getContent());
        $this->assertStringContainsString($this->product->name, $this->actingAs($this->manager)->get(route('debts.show', $this->customer))->getContent());
    }

    public function test_the_sidebar_link_follows_the_feature_but_stays_while_someone_owes(): void
    {
        $this->actingAs($this->cashier)->get(route('returns.index'))->assertSee('href="/debts"', false);

        // Feature off, nobody owes: the way in is gone.
        Setting::set('feature_overrides', ['customer_credit' => false], 'license', 'json');
        app(\App\Services\FeatureService::class)->flush();
        $this->actingAs($this->cashier)->get(route('returns.index'))->assertDontSee('href="/debts"', false);

        // Feature off, but a balance is owed: collecting it must stay reachable, and the screens still work.
        $this->customer->forceFill(['balance' => 50])->save();
        $this->actingAs($this->cashier)->get(route('returns.index'))->assertSee('href="/debts"', false);
        $this->actingAs($this->cashier)->get(route('debts.index'))->assertOk();
    }

    public function test_repayments_still_work_after_the_feature_is_switched_off(): void
    {
        $this->creditSale(total: 100);
        Setting::set('feature_overrides', ['customer_credit' => false], 'license', 'json');
        app(\App\Services\FeatureService::class)->flush();

        $this->actingAs($this->cashier)->post($this->payUrl(), ['amount' => 40, 'currency' => 'USD', 'method' => 'cash'])->assertSessionHasNoErrors();

        $this->assertSame(60.0, $this->owed());
    }

    // --- Statement and slip -------------------------------------------------------------------------

    public function test_the_statement_prints_with_a_running_balance_in_the_receipt_language(): void
    {
        $this->creditSale(total: 100);
        $this->repay(30);
        $range = ['from' => now()->subDays(5)->toDateString(), 'to' => now()->toDateString()];

        Setting::set('receipt_language', 'en', 'receipt', 'string');
        $en = $this->actingAs($this->cashier)->get(route('debts.statement', ['customer' => $this->customer] + $range))->assertOk()->getContent();
        $this->assertStringContainsString('Statement of account', $en);
        $this->assertStringContainsString('$70.00', $en);
        $this->assertStringContainsString('dir="ltr"', $en);

        Setting::set('receipt_language', 'ar', 'receipt', 'string');
        $ar = $this->actingAs($this->cashier)->get(route('debts.statement', ['customer' => $this->customer] + $range))->assertOk()->getContent();
        $this->assertStringContainsString('كشف حساب', $ar);
        $this->assertStringContainsString('<html lang="ar" dir="rtl">', $ar);
        $this->assertStringContainsString('$70.00', $ar, 'Western digits in Arabic too');

        $this->actingAs($this->cashier)->get(route('debts.statement', ['customer' => $this->customer] + $range + ['format' => 'xlsx']))->assertOk()->assertDownload();
        $pdf = $this->actingAs($this->cashier)->get(route('debts.statement', ['customer' => $this->customer] + $range + ['format' => 'pdf']))->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
    }

    public function test_the_payment_slip_shows_the_payment_what_it_paid_and_the_balance_left(): void
    {
        $sale = $this->creditSale(total: 100);
        $payment = $this->repay(4500000, ['currency' => 'LBP', 'reference' => 'R-9']);

        foreach (['thermal' => 'debts.slip', 'a4' => 'debts.slip-a4'] as $format => $view) {
            $res = $this->actingAs($this->cashier)->get(route('debts.slip', ['payment' => $payment, 'format' => $format]))->assertOk()->assertViewIs($view);
            $res->assertSee($payment->number)->assertSee($sale->receipt_number)->assertSee('4,500,000')->assertSee('$50.00')->assertSee('R-9');
        }

        // Outside the desktop shell the silent-print route answers "not printed, not desktop" so the page opens the popup.
        $this->actingAs($this->cashier)->postJson(route('debts.payments.print', $payment))->assertOk()->assertJson(['printed' => false, 'desktop' => false]);
    }
}
