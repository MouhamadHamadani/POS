<?php

namespace Tests\Feature\Debts;

use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\User;
use App\Services\CustomerLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Shared rig for the debt-book tests: a $1 product (so a quantity is the sale total), a cashier with an open
 * shift, a manager, and a customer with room to buy on account.
 */
abstract class DebtBookTestCase extends TestCase
{
    use RefreshDatabase;

    protected User $cashier;
    protected User $manager;
    protected Shift $shift;
    protected Product $product;
    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::set('exchange_rate', 90000, 'currency', 'float');
        Setting::set('lbp_rounding_step', 1000, 'currency', 'float');

        $this->cashier = User::factory()->create();
        $this->manager = User::factory()->manager()->create();
        $this->shift = Shift::create([
            'user_id' => $this->cashier->id, 'opened_at' => now(),
            'opening_cash_usd' => 50, 'opening_cash_lbp' => 0, 'status' => Shift::STATUS_OPEN,
        ]);
        $this->product = Product::factory()->create(['price_usd' => 1, 'cost_usd' => 0.4, 'stock_qty' => 1000000, 'is_taxable' => false]);
        $this->customer = $this->customer();
    }

    protected function customer(array $attrs = []): Customer
    {
        return Customer::create($attrs + [
            'name' => 'Nadia Khoury', 'phone' => '03123456', 'customer_group' => Customer::GROUP_RETAIL,
            'credit_limit' => 100000, 'balance' => 0, 'loyalty_points' => 0, 'is_active' => true,
        ]);
    }

    /** Ring up a sale of $total through the till's endpoint, $cash of it paid now and the rest on account. */
    protected function creditSale(?Customer $customer = null, float $total = 100, ?string $due = null, float $cash = 0.0, ?User $by = null): Sale
    {
        $payment = ['amount_credit' => round($total - $cash, 4)];
        if ($cash > 0) {
            $payment['amount_usd'] = $cash;
        }
        if ($due !== null) {
            $payment['due_date'] = $due;
        }

        $by ??= $this->cashier;
        $res = $this->actingAs($by)->postJson('/pos/api/sales', [
            'cart' => [['product_id' => $this->product->id, 'qty' => $total]],
            'payment' => $payment,
            'customer_id' => ($customer ?? $this->customer)->id,
        ]);
        $res->assertCreated();

        return Sale::findOrFail($res->json('sale.id'));
    }

    protected function ledger(): CustomerLedgerService
    {
        return app(CustomerLedgerService::class);
    }

    /** A repayment through the service. Cash by default, into the cashier's open shift. */
    protected function repay(float $amount, array $extra = [], ?User $by = null, ?Customer $customer = null): CustomerPayment
    {
        return $this->ledger()->recordPayment($customer ?? $this->customer, $extra + ['amount' => $amount, 'method' => 'cash'], $by ?? $this->cashier);
    }

    /** The validation keys a callable fails on, [] when it does not fail. */
    protected function invalidKeys(callable $fn): array
    {
        try {
            $fn();
        } catch (ValidationException $e) {
            return array_keys($e->errors());
        }

        return [];
    }

    protected function owed(?Customer $customer = null): float
    {
        return round((float) ($customer ?? $this->customer)->fresh()->balance, 2);
    }

    /** Move a receipt's due date (the till only takes today or later, so the past is set straight in the table). */
    protected function dueOn(Sale $sale, string $date): void
    {
        $sale->forceFill(['due_date' => $date])->save();
    }
}
