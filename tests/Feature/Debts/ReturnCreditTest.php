<?php

namespace Tests\Feature\Debts;

use App\Models\ReturnTransaction;
use App\Models\Sale;
use App\Models\SaleItem;

/**
 * A return refunded "to the account" pays down the customer's debt: the returned sale's own first, then the rest
 * oldest first, and only what is left over becomes store credit. A sale that still owes money takes no cash refund.
 */
class ReturnCreditTest extends DebtBookTestCase
{
    /** Return $qty of the sale's single line, as a manager (auto-approved). */
    private function returnItems(Sale $sale, float $qty, string $method = 'account')
    {
        $item = SaleItem::where('sale_id', $sale->id)->firstOrFail();

        return $this->actingAs($this->manager)->post(route('returns.store', $sale), [
            'items' => [['sale_item_id' => $item->id, 'qty_returned' => $qty, 'restock' => 1]],
            'reason' => 'defective',
            'refund_method' => $method,
        ]);
    }

    public function test_an_account_refund_pays_the_returned_sales_debt_first_then_the_oldest_others(): void
    {
        $old = $this->creditSale(total: 40, due: now()->addDays(5)->toDateString());
        $returned = $this->creditSale(total: 100, due: now()->addDays(30)->toDateString());
        $newer = $this->creditSale(total: 25, due: now()->addDays(10)->toDateString());
        $this->assertSame(165.0, $this->owed());

        // 70 of the $100 sale's items come back: $70 off that sale's own debt, none of the others touched.
        $this->returnItems($returned, 70)->assertRedirect();
        $this->assertSame(30.0, $this->ledger()->outstandingForSale($returned));
        $this->assertSame(40.0, $this->ledger()->outstandingForSale($old));
        $this->assertSame(95.0, $this->owed());

        // The other 30 are the rest of that sale's own debt.
        $this->returnItems($returned, 30)->assertRedirect();
        $this->assertSame(0.0, $this->ledger()->outstandingForSale($returned));
        $this->assertSame(65.0, $this->owed());
        $this->assertSame(ReturnTransaction::STATUS_COMPLETED, ReturnTransaction::latest('id')->first()->status);
    }

    public function test_a_refund_bigger_than_the_returned_sales_debt_spills_to_the_oldest_then_to_store_credit(): void
    {
        $returned = $this->creditSale(total: 100, due: now()->addDays(30)->toDateString(), cash: 70); // owes 30
        $old = $this->creditSale(total: 20, due: now()->addDays(5)->toDateString());
        $late = $this->creditSale(total: 20, due: now()->addDays(15)->toDateString());
        $this->assertSame(70.0, $this->owed());

        // Account refund of $100: 30 clears the returned sale, 20 + 20 the other two, the last $30 is store credit.
        $this->returnItems($returned, 100)->assertRedirect();

        $this->assertSame(0.0, $this->ledger()->outstandingForSale($returned));
        $this->assertSame(0.0, $this->ledger()->outstandingForSale($old));
        $this->assertSame(0.0, $this->ledger()->outstandingForSale($late));
        $this->assertSame(-30.0, $this->owed(), 'a negative balance: the store owes the customer');
        $this->assertTrue($this->ledger()->reconcile($this->customer));
        $this->assertSame(0.0, $this->ledger()->debtOf($this->customer), 'store credit is not debt');
    }

    public function test_cash_back_is_refused_while_the_sale_still_owes_money_but_account_and_exchange_are_fine(): void
    {
        $sale = $this->creditSale(total: 100);

        $this->returnItems($sale, 10, 'cash_usd')->assertSessionHasErrors('refund_method');
        $this->returnItems($sale, 10, 'card')->assertSessionHasErrors('refund_method');
        $this->assertSame(0, ReturnTransaction::count());
        $this->assertSame(100.0, $this->owed());

        $this->returnItems($sale, 10, 'exchange')->assertSessionHasNoErrors();
        $this->assertSame(100.0, $this->owed(), 'an exchange moves no money');

        $this->returnItems($sale, 10, 'account')->assertSessionHasNoErrors();
        $this->assertSame(90.0, $this->owed());
    }

    public function test_once_the_debt_is_paid_cash_back_is_allowed_again(): void
    {
        $sale = $this->creditSale(total: 100);
        $this->repay(100);

        $this->returnItems($sale, 10, 'cash_usd')->assertSessionHasNoErrors();

        $this->assertSame(1, ReturnTransaction::count());
    }

    public function test_the_return_screen_says_what_is_owed_and_offers_only_the_methods_that_work(): void
    {
        $sale = $this->creditSale(total: 100);

        $html = $this->actingAs($this->manager)->get(route('returns.create', $sale))->assertOk()->getContent();

        $this->assertStringContainsString(__('returns.debt_outstanding_title', ['amount' => '$100.00']), $html);
        $this->assertStringContainsString('value="account" selected', preg_replace('/\s+/', ' ', $html));
        $this->assertStringNotContainsString('value="cash_usd"', $html);
        $this->assertStringContainsString('value="exchange"', $html);
    }
}
