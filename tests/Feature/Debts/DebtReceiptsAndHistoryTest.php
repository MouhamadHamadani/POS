<?php

namespace Tests\Feature\Debts;

use App\Models\PrintTemplate;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\User;
use Carbon\Carbon;

/** A credit sale on paper (receipt, A4, custom template, test print), in Sales History and on the sale page. */
class DebtReceiptsAndHistoryTest extends DebtBookTestCase
{
    private function receipt(Sale $sale, string $query = ''): string
    {
        return $this->actingAs($this->cashier)->get("/pos/receipts/{$sale->id}/print?auto=0".$query)->assertOk()->getContent();
    }

    public function test_a_credit_receipt_prints_on_account_the_due_date_and_the_balance_owed(): void
    {
        Setting::set('receipt_language', 'en', 'receipt', 'string');
        $sale = $this->creditSale(total: 100, due: now()->addDays(12)->toDateString(), cash: 40);

        foreach ([$this->receipt($sale), $this->receipt($sale, '&format=a4')] as $html) {
            $this->assertStringContainsString('On account', $html);
            $this->assertStringContainsString('$60.00', $html, 'the on-account amount');
            $this->assertStringContainsString('Due date', $html);
            $this->assertStringContainsString(now()->addDays(12)->toDateString(), $html);
            $this->assertStringContainsString('Total balance owed', $html);
            $this->assertStringContainsString('Nadia Khoury', $html);
        }
    }

    public function test_a_reprint_shows_the_balance_the_original_showed_not_the_live_one(): void
    {
        Setting::set('receipt_language', 'en', 'receipt', 'string');
        $first = $this->creditSale(total: 100);
        $second = $this->creditSale(total: 30);
        $this->repay(50);

        // The second receipt said $130 when it was made; today the customer owes $80, and the reprint still says $130.
        $reprint = $this->receipt($second, '&copy=1&src=history');

        $this->assertStringContainsString('COPY', $reprint);
        $this->assertMatchesRegularExpression('/Total balance owed.*?\$130\.00/s', $reprint);
        $this->assertStringNotContainsString('$80.00', $reprint);
        $this->assertEquals(130, $second->fresh()->customer_balance_after_usd);
        $this->assertEquals(100, $first->fresh()->customer_balance_after_usd);
    }

    public function test_the_balance_line_follows_the_store_setting_but_the_due_date_stays(): void
    {
        Setting::set('receipt_language', 'en', 'receipt', 'string');
        $sale = $this->creditSale(total: 100);

        Setting::set('show_balance_on_receipt', false, 'pos', 'bool');
        $html = $this->receipt($sale);

        $this->assertStringContainsString('Due date', $html);
        $this->assertStringNotContainsString('Total balance owed', $html);
    }

    public function test_a_sale_not_on_account_prints_neither(): void
    {
        $res = $this->actingAs($this->cashier)->postJson('/pos/api/sales', [
            'cart' => [['product_id' => $this->product->id, 'qty' => 10]], 'payment' => ['amount_usd' => 10], 'customer_id' => $this->customer->id,
        ])->assertCreated();

        $html = $this->receipt(Sale::findOrFail($res->json('sale.id')));

        $this->assertStringNotContainsString('Due date', $html);
        $this->assertStringNotContainsString('Total balance owed', $html);
    }

    public function test_the_receipt_follows_the_receipt_language_setting_not_the_cashiers(): void
    {
        $sale = $this->creditSale(total: 100);

        Setting::set('receipt_language', 'ar', 'receipt', 'string');
        $ar = $this->receipt($sale);
        $this->assertStringContainsString('تاريخ الاستحقاق', $ar);
        $this->assertStringContainsString('إجمالي الرصيد المستحق', $ar);
        $this->assertStringNotContainsString('Due date', $ar);

        Setting::set('receipt_language', 'bilingual', 'receipt', 'string');
        $both = $this->receipt($sale);
        $this->assertStringContainsString('Due date', $both);
        $this->assertStringContainsString('تاريخ الاستحقاق', $both);
    }

    public function test_a_custom_template_can_print_the_debt_tokens(): void
    {
        PrintTemplate::create([
            'name' => 'Mine', 'type' => 'receipt', 'is_default' => true, 'is_active' => true,
            'template_html' => '<html><body>OWE={{on_account}} DUE={{due_date}} BAL={{balance_owed}} N={{customer}}</body></html>',
        ]);
        $sale = $this->creditSale(total: 100, due: now()->addDays(2)->toDateString());

        $html = $this->receipt($sale);

        $this->assertStringContainsString('OWE=$100.00', $html);
        $this->assertStringContainsString('DUE='.now()->addDays(2)->toDateString(), $html);
        $this->assertStringContainsString('BAL=$100.00', $html);
    }

    public function test_the_settings_test_print_shows_an_on_account_sale(): void
    {
        $admin = User::factory()->admin()->create();

        $html = $this->actingAs($admin)->post('/settings/receipt/test-print', ['settings' => ['receipt_width' => 80, 'receipt_language' => 'en']])
            ->assertOk()->getContent();

        $this->assertStringContainsString('On account', $html);
        $this->assertStringContainsString('Due date', $html);
        $this->assertStringContainsString('Total balance owed', $html);
        $this->assertStringContainsString('Sample Customer', $html);
    }

    public function test_the_two_new_pos_settings_save_and_default_credit_days_is_validated(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('settings.update'), ['group' => 'pos', 'settings' => ['default_credit_days' => 21, 'show_balance_on_receipt' => 1]])
            ->assertSessionHasNoErrors();
        $this->assertSame(21, Setting::get('default_credit_days'));
        $this->assertTrue((bool) Setting::get('show_balance_on_receipt'));

        $this->actingAs($admin)->post(route('settings.update'), ['group' => 'pos', 'settings' => ['default_credit_days' => -3]])
            ->assertSessionHasErrors('settings.default_credit_days');
        $this->assertSame(21, Setting::get('default_credit_days'));
    }

    // --- Sales History -----------------------------------------------------------------------------

    public function test_sales_history_flags_credit_sales_and_filters_by_outstanding_and_overdue(): void
    {
        $paid = $this->creditSale(total: 20);
        $this->repay(20);
        $open = $this->creditSale(total: 50, due: now()->addDays(10)->toDateString());
        $late = $this->creditSale(total: 70);
        $this->dueOn($late, Carbon::today()->subDays(2)->toDateString());
        $dueToday = $this->creditSale(total: 5);
        $this->dueOn($dueToday, Carbon::today()->toDateString());
        $cash = $this->actingAs($this->cashier)->postJson('/pos/api/sales', [
            'cart' => [['product_id' => $this->product->id, 'qty' => 9]], 'payment' => ['amount_usd' => 9],
        ])->assertCreated()->json('sale.receipt_number');
        // A credit sale from before the book: flat columns only, no ledger lines.
        $legacy = Sale::create([
            'receipt_number' => 'OLD-1', 'customer_id' => $this->customer->id, 'user_id' => $this->cashier->id, 'shift_id' => $this->shift->id,
            'subtotal_usd' => 33, 'total_usd' => 33, 'total_lbp' => 0, 'exchange_rate' => 90000, 'payment_method' => 'credit',
            'amount_credit_usd' => 33, 'status' => Sale::STATUS_COMPLETED,
        ]);

        $list = fn (array $q) => $this->actingAs($this->manager)->get(route('sales.index', $q + ['q' => 'REC']))->assertOk()->getContent();
        $all = $this->actingAs($this->manager)->get(route('sales.index', ['from' => now()->subDay()->toDateString()]))->assertOk()->getContent();
        $this->assertStringContainsString('On account', $all, 'the badge');
        $this->assertStringContainsString('$50.00', $all, 'the outstanding column');

        $outstanding = $list(['debt' => 'outstanding']);
        foreach ([$open, $late, $dueToday] as $s) {
            $this->assertStringContainsString($s->receipt_number, $outstanding);
        }
        $this->assertStringNotContainsString($paid->receipt_number, $outstanding, 'settled');
        $this->assertStringNotContainsString($cash, $outstanding, 'not on account');

        $overdue = $list(['debt' => 'overdue']);
        $this->assertStringContainsString($late->receipt_number, $overdue);
        $this->assertStringNotContainsString($open->receipt_number, $overdue);
        $this->assertStringNotContainsString($dueToday->receipt_number, $overdue, 'due today is not late');

        // The legacy sale has no per-receipt debt to show; it is in neither filter and the page still renders.
        $this->actingAs($this->manager)->get(route('sales.index', ['debt' => 'outstanding', 'q' => 'OLD']))->assertOk()->assertDontSee('OLD-1');
        $this->actingAs($this->manager)->get(route('sales.index', ['q' => 'OLD']))->assertOk()->assertSee('OLD-1')->assertSee('On account');
        $this->actingAs($this->manager)->get(route('sales.index', ['debt' => 'nonsense']))->assertSessionHasErrors('debt');
    }

    public function test_the_sale_page_shows_the_due_date_what_is_owed_and_each_payment_with_its_date(): void
    {
        $sale = $this->creditSale(total: 100, due: now()->addDays(5)->toDateString());
        $payment = $this->repay(35);

        $html = $this->actingAs($this->cashier)->get(route('sales.show', $sale))->assertOk()->getContent();

        $this->assertStringContainsString(now()->addDays(5)->toDateString(), $html);
        $this->assertStringContainsString('$35.00', $html);
        $this->assertStringContainsString('$65.00', $html);
        $this->assertStringContainsString($payment->number, $html);
        $this->assertStringContainsString($payment->paid_at->format('Y-m-d'), $html);

        $legacy = Sale::create([
            'receipt_number' => 'OLD-2', 'customer_id' => $this->customer->id, 'user_id' => $this->cashier->id, 'shift_id' => $this->shift->id,
            'subtotal_usd' => 10, 'total_usd' => 10, 'total_lbp' => 0, 'exchange_rate' => 90000, 'payment_method' => 'credit',
            'amount_credit_usd' => 10, 'status' => Sale::STATUS_COMPLETED,
        ]);
        $this->actingAs($this->cashier)->get(route('sales.show', $legacy))->assertOk()->assertSee(__('sales.debt_legacy'));
    }
}
