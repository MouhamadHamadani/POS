<?php

namespace Tests\Feature\Debts;

use App\Models\Setting;
use Carbon\Carbon;

/** What the till offers for selling on account, and what the customer chip is told. */
class TillCreditTest extends DebtBookTestCase
{
    public function test_the_till_offers_on_account_with_the_feature_and_hides_it_without(): void
    {
        $html = $this->actingAs($this->cashier)->get('/pos')->assertOk()->getContent();
        $this->assertStringContainsString("setPayMode('credit')", $html);
        $this->assertStringContainsString('payment.amount_credit', $html);

        Setting::set('feature_overrides', ['customer_credit' => false], 'license', 'json');
        app(\App\Services\FeatureService::class)->flush();

        $html = $this->actingAs($this->cashier)->get('/pos')->assertOk()->getContent();
        $this->assertStringNotContainsString("setPayMode('credit')", $html);
        $this->assertStringNotContainsString('x-model.number="payment.amount_credit"', $html);
        $this->assertStringNotContainsString('x-model="payment.due_date"', $html, 'no on-account panel');
    }

    public function test_the_customer_search_carries_the_limit_the_terms_and_the_overdue_amount(): void
    {
        $c = $this->customer(['name' => 'Late Larry', 'phone' => '70123456', 'credit_limit' => 500, 'payment_terms_days' => 14]);
        $this->dueOn($this->creditSale($c, 40), Carbon::today()->subDays(3)->toDateString());
        $this->creditSale($c, 25, now()->addDays(5)->toDateString());

        $row = $this->actingAs($this->cashier)->getJson('/pos/api/customers/search?q=Larry')->assertOk()->json('0');

        $this->assertEquals(65, $row['balance']);
        $this->assertEquals(500, $row['credit_limit']);
        $this->assertSame(14, $row['payment_terms_days']);
        $this->assertEquals(40, $row['overdue_usd'], 'only the receipt past its due date');

        $fresh = $this->actingAs($this->cashier)->getJson('/pos/api/customers/search?q=Nadia')->assertOk()->json('0');
        $this->assertEquals(0, $fresh['overdue_usd']);
    }

    public function test_the_sale_response_carries_what_the_till_prints_on_the_confirmation(): void
    {
        $res = $this->actingAs($this->cashier)->postJson('/pos/api/sales', [
            'cart' => [['product_id' => $this->product->id, 'qty' => 50]],
            'payment' => ['amount_credit' => 30, 'amount_usd' => 20, 'due_date' => now()->addDays(9)->toDateString()],
            'customer_id' => $this->customer->id,
        ])->assertCreated();

        $res->assertJsonPath('sale.payment_method', 'split');
        $this->assertEquals(30, $res->json('sale.amount_credit_usd'));
        $this->assertSame(now()->addDays(9)->toDateString(), substr((string) $res->json('sale.due_date'), 0, 10));
    }
}
