<?php

namespace Tests\Feature\Debts;

use App\Models\AuditLog;
use App\Models\CustomerLedgerEntry;
use App\Models\Sale;
use App\Models\Setting;
use App\Services\SaleService;
use Illuminate\Validation\ValidationException;

/**
 * Selling on account: "pay now / pay later" is a split payment with an on-account line. The debt lands on the
 * customer's ledger and carries a due date.
 */
class CreditSaleTest extends DebtBookTestCase
{
    public function test_a_pay_later_sale_puts_the_whole_total_on_the_ledger_with_a_due_date(): void
    {
        $sale = $this->creditSale(total: 100);

        $this->assertSame('credit', $sale->payment_method);
        $this->assertEquals(100, $sale->amount_credit_usd);
        $this->assertSame(now()->addDays(30)->toDateString(), $sale->due_date->toDateString());
        $this->assertEquals(100, $sale->customer_balance_after_usd, 'the receipt keeps what the customer owed right after it');

        $entry = CustomerLedgerEntry::sole();
        $this->assertSame(CustomerLedgerEntry::TYPE_CREDIT_SALE, $entry->type);
        $this->assertSame($sale->id, $entry->sale_id);
        $this->assertEquals(100, $entry->amount_usd);

        $this->assertSame(100.0, $this->owed());
        $this->assertSame([['credit', 'USD', 100.0]], $sale->payments->map(fn ($p) => [$p->method, $p->currency, (float) $p->amount])->all());
        $this->assertTrue(AuditLog::where('action', 'customer_credit_sale')->exists());
        $audit = AuditLog::where('action', 'sale')->latest('id')->first()->new_values;
        $this->assertEquals(100, $audit['credit_usd']);
        $this->assertSame($sale->due_date->toDateString(), $audit['due_date']);
    }

    public function test_a_part_cash_part_credit_sale_owes_only_the_credit_part(): void
    {
        $sale = $this->creditSale(total: 100, cash: 40);

        $this->assertSame('split', $sale->payment_method);
        $this->assertEquals(40, $sale->amount_tendered_usd);
        $this->assertEquals(60, $sale->amount_credit_usd);
        $this->assertEquals(60, CustomerLedgerEntry::sole()->amount_usd);
        $this->assertSame(60.0, $this->owed());
        $this->assertCount(2, $sale->payments);
    }

    public function test_a_sale_with_no_credit_has_no_due_date_and_no_ledger_entry(): void
    {
        $res = $this->actingAs($this->cashier)->postJson('/pos/api/sales', [
            'cart' => [['product_id' => $this->product->id, 'qty' => 10]],
            'payment' => ['amount_usd' => 10, 'due_date' => now()->addDays(5)->toDateString()],
            'customer_id' => $this->customer->id,
        ])->assertCreated();

        $this->assertNull(Sale::findOrFail($res->json('sale.id'))->due_date);
        $this->assertSame(0, CustomerLedgerEntry::count());
        $this->assertSame(0.0, $this->owed());
    }

    public function test_the_due_date_comes_from_the_customers_terms_else_the_store_default(): void
    {
        Setting::set('default_credit_days', 45, 'pos', 'int');
        $this->assertSame(now()->addDays(45)->toDateString(), $this->creditSale(total: 10)->due_date->toDateString());

        $own = $this->customer(['name' => 'Rami', 'phone' => '71999888', 'payment_terms_days' => 7]);
        $this->assertSame(now()->addDays(7)->toDateString(), $this->creditSale($own, 10)->due_date->toDateString());

        $given = now()->addDays(3)->toDateString();
        $this->assertSame($given, $this->creditSale($own, 10, $given)->due_date->toDateString());
    }

    public function test_a_due_date_in_the_past_is_rejected(): void
    {
        $this->actingAs($this->cashier)->postJson('/pos/api/sales', [
            'cart' => [['product_id' => $this->product->id, 'qty' => 10]],
            'payment' => ['amount_credit' => 10, 'due_date' => now()->subDay()->toDateString()],
            'customer_id' => $this->customer->id,
        ])->assertUnprocessable();

        $this->assertSame(0, Sale::count());
        $this->assertSame(0, CustomerLedgerEntry::count());
    }

    public function test_credit_without_a_customer_is_rejected(): void
    {
        $this->actingAs($this->cashier)->postJson('/pos/api/sales', [
            'cart' => [['product_id' => $this->product->id, 'qty' => 10]],
            'payment' => ['amount_credit' => 10],
        ])->assertUnprocessable()->assertJsonPath('error.customer.0', __('messages.credit_customer_required'));

        $this->assertSame(0, Sale::count());
    }

    public function test_credit_over_the_limit_is_rejected_and_the_message_says_the_limit_and_what_is_left(): void
    {
        $c = $this->customer(['name' => 'Limited', 'phone' => '70111222', 'credit_limit' => 100]);
        $this->creditSale($c, 80);

        $res = $this->actingAs($this->cashier)->postJson('/pos/api/sales', [
            'cart' => [['product_id' => $this->product->id, 'qty' => 30]],
            'payment' => ['amount_credit' => 30],
            'customer_id' => $c->id,
        ])->assertUnprocessable();

        // What is already owed counts: $80 + $30 is over a $100 limit, $20 is left.
        $msg = $res->json('error.customer.0');
        $this->assertStringContainsString('$100.00', $msg);
        $this->assertStringContainsString('$20.00', $msg);
        $this->assertSame(1, Sale::count());
        $this->assertSame(80.0, $this->owed($c));
    }

    public function test_a_credit_limit_of_zero_means_no_credit(): void
    {
        $c = $this->customer(['name' => 'No credit', 'phone' => '70333444', 'credit_limit' => 0]);

        $this->actingAs($this->cashier)->postJson('/pos/api/sales', [
            'cart' => [['product_id' => $this->product->id, 'qty' => 5]],
            'payment' => ['amount_credit' => 5],
            'customer_id' => $c->id,
        ])->assertUnprocessable();

        $this->assertSame(0, Sale::count());
    }

    public function test_with_the_feature_off_the_credit_is_refused_not_dropped(): void
    {
        Setting::set('tier', 'starter', 'license');

        $this->actingAs($this->cashier)->postJson('/pos/api/sales', [
            'cart' => [['product_id' => $this->product->id, 'qty' => 5]],
            'payment' => ['amount_credit' => 5],
            'customer_id' => $this->customer->id,
        ])->assertForbidden();

        $this->assertSame(0, Sale::count());
        $this->assertSame(0, CustomerLedgerEntry::count());
    }

    public function test_the_service_refuses_credit_with_the_feature_off_too(): void
    {
        // On a plan that has the customers area but not the debt book: the override switches only credit off.
        Setting::set('tier', 'standard', 'license');
        Setting::set('feature_overrides', ['customer_credit' => false], 'license', 'json');
        app(\App\Services\FeatureService::class)->flush();

        $keys = $this->invalidKeys(fn () => app(SaleService::class)->process(
            [['product_id' => $this->product->id, 'qty' => 5]], ['amount_credit' => 5], $this->cashier, $this->shift->id, $this->customer->id,
        ));

        $this->assertSame(['payment'], $keys);
        $this->assertSame(0, Sale::count());
    }

    public function test_a_credit_only_sale_owes_exactly_the_sale_total_when_the_till_rounded_to_cents(): void
    {
        // $1.4985 total; the till shows (and sends) $1.50 on account.
        $this->product->update(['price_usd' => 1.4985]);

        $res = $this->actingAs($this->cashier)->postJson('/pos/api/sales', [
            'cart' => [['product_id' => $this->product->id, 'qty' => 1]],
            'payment' => ['amount_credit' => 1.50],
            'customer_id' => $this->customer->id,
        ])->assertCreated();

        $sale = Sale::findOrFail($res->json('sale.id'));
        $this->assertEquals(1.4985, $sale->amount_credit_usd);
        $this->assertEquals(1.4985, CustomerLedgerEntry::sole()->amount_usd);
    }

    public function test_voiding_a_credit_sale_takes_its_debt_off_the_book(): void
    {
        $sale = $this->creditSale(total: 100);
        $other = $this->creditSale(total: 25);

        app(SaleService::class)->voidSale($sale, $this->manager->id, 'rung up twice');

        $this->assertSame(25.0, $this->owed());
        $this->assertSame(0.0, $this->ledger()->outstandingForSale($sale));
        $this->assertSame(25.0, $this->ledger()->outstandingForSale($other));
        $this->assertSame(CustomerLedgerEntry::TYPE_VOID_REVERSAL, CustomerLedgerEntry::latest('id')->first()->type);
        $this->assertTrue($this->ledger()->reconcile($this->customer));
    }

    public function test_a_credit_sale_with_a_payment_applied_cannot_be_voided_until_the_payment_is(): void
    {
        $sale = $this->creditSale(total: 100);
        $payment = $this->repay(30);

        $thrown = null;
        try {
            app(SaleService::class)->voidSale($sale, $this->manager->id, 'mistake');
        } catch (ValidationException $e) {
            $thrown = $e;
        }
        $this->assertNotNull($thrown, 'refused');
        $this->assertSame(__('messages.void_credit_has_payments'), $thrown->errors()['sale'][0]);
        $this->assertSame(Sale::STATUS_COMPLETED, $sale->fresh()->status, 'nothing was half-done');
        $this->assertSame(70.0, $this->owed());

        $this->ledger()->voidPayment($payment, $this->manager, 'wrong receipt');
        app(SaleService::class)->voidSale($sale->fresh(), $this->manager->id, 'mistake');

        $this->assertSame(0.0, $this->owed());
        $this->assertSame(Sale::STATUS_VOIDED, $sale->fresh()->status);
    }

    public function test_a_sale_from_before_the_book_is_voided_out_of_the_opening_pot(): void
    {
        // A pre-book credit sale: balance and the flat column, no ledger row for the receipt.
        $c = $this->customer(['name' => 'Old', 'phone' => '70555666', 'balance' => 40]);
        $sale = Sale::create([
            'receipt_number' => 'OLD-1', 'customer_id' => $c->id, 'user_id' => $this->cashier->id, 'shift_id' => $this->shift->id,
            'subtotal_usd' => 40, 'total_usd' => 40, 'total_lbp' => 3600000, 'exchange_rate' => 90000, 'payment_method' => 'credit',
            'amount_credit_usd' => 40, 'status' => Sale::STATUS_COMPLETED,
        ]);
        (require database_path('migrations/2026_11_13_000003_backfill_customer_opening_balances.php'))->up();

        app(SaleService::class)->voidSale($sale, $this->manager->id, 'old mistake');

        $this->assertSame(0.0, $this->owed($c));
        $this->assertTrue($this->ledger()->reconcile($c));
    }
}
