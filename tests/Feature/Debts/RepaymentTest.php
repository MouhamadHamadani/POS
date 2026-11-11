<?php

namespace Tests\Feature\Debts;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\CustomerLedgerEntry;
use App\Models\CustomerPayment;
use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Repayments: where the money is applied, what it is refused for, voiding, write-offs, the statement and the
 * reconcile check. Debt is USD; an LBP repayment keeps the rate it was taken at.
 */
class RepaymentTest extends DebtBookTestCase
{
    private function outstanding(\App\Models\Sale $sale): float
    {
        return $this->ledger()->outstandingForSale($sale);
    }

    /** A customer with a $40 balance from before the book (the opening pot) and three receipts of $100, $50, $30. */
    private function rigWithOpeningAndThreeReceipts(): array
    {
        $c = $this->customer(['name' => 'Rich', 'phone' => '70777888', 'balance' => 40]);
        (require database_path('migrations/2026_11_13_000003_backfill_customer_opening_balances.php'))->up();

        $a = $this->creditSale($c, 100, now()->addDays(30)->toDateString());
        $b = $this->creditSale($c, 50, now()->addDays(10)->toDateString());
        $d = $this->creditSale($c, 30, now()->addDays(20)->toDateString());

        return [$c, $a, $b, $d];
    }

    public function test_a_repayment_goes_to_the_opening_pot_first_then_receipts_by_due_date(): void
    {
        [$c, $a, $b, $d] = $this->rigWithOpeningAndThreeReceipts();
        $this->assertSame(220.0, $this->owed($c));

        // $40 opening + $50 (due in 10 days) + $30 (20 days) + $20 of the $100 (30 days).
        $payment = $this->repay(140, customer: $c);

        $this->assertSame(80.0, $this->owed($c));
        $this->assertSame(0.0, $this->outstanding($b));
        $this->assertSame(0.0, $this->outstanding($d));
        $this->assertSame(80.0, $this->outstanding($a));
        $this->assertEquals(0, CustomerLedgerEntry::where('customer_id', $c->id)->whereNull('sale_id')->sum('amount_usd'), 'the opening pot is cleared');
        $this->assertCount(4, $payment->entries);
        $this->assertTrue($this->ledger()->reconcile($c));
        $this->assertStringStartsWith('PAY-', $payment->number);
    }

    public function test_choosing_receipts_applies_the_amount_to_those_receipts(): void
    {
        [$c, $a, $b, $d] = $this->rigWithOpeningAndThreeReceipts();

        $this->repay(60, ['allocations' => [
            ['sale_id' => $a->id, 'amount_usd' => 35],
            ['sale_id' => $d->id, 'amount_usd' => 25],
        ]], customer: $c);

        $this->assertSame(65.0, $this->outstanding($a));
        $this->assertSame(5.0, $this->outstanding($d));
        $this->assertSame(50.0, $this->outstanding($b), 'not chosen, not touched');
        $this->assertSame(160.0, $this->owed($c));
    }

    public function test_allocations_that_do_not_add_up_to_the_payment_are_refused(): void
    {
        [$c, $a] = $this->rigWithOpeningAndThreeReceipts();
        $before = CustomerLedgerEntry::count();

        $keys = $this->invalidKeys(fn () => $this->repay(60, ['allocations' => [['sale_id' => $a->id, 'amount_usd' => 50]]], customer: $c));

        $this->assertSame(['allocations'], $keys);
        $this->assertSame($before, CustomerLedgerEntry::count());
        $this->assertSame(0, CustomerPayment::count());
    }

    public function test_more_than_a_receipt_owes_or_a_receipt_that_is_not_open_is_refused(): void
    {
        [$c, $a, $b] = $this->rigWithOpeningAndThreeReceipts();
        $other = $this->customer(['name' => 'Else', 'phone' => '70999000']);
        $foreign = $this->creditSale($other, 20);

        $this->assertSame(['allocations'], $this->invalidKeys(fn () => $this->repay(60, ['allocations' => [['sale_id' => $b->id, 'amount_usd' => 60]]], customer: $c)));
        $this->assertSame(['allocations'], $this->invalidKeys(fn () => $this->repay(20, ['allocations' => [['sale_id' => $foreign->id, 'amount_usd' => 20]]], customer: $c)));
    }

    public function test_paying_more_than_is_owed_is_refused(): void
    {
        $this->creditSale(total: 100);

        $this->assertSame(['amount_usd'], $this->invalidKeys(fn () => $this->repay(100.50)));
        $this->assertSame(100.0, $this->owed());
        $this->assertSame(0, CustomerPayment::count());

        $this->repay(100);
        $this->assertSame(0.0, $this->owed());
        $this->assertSame(['amount_usd'], $this->invalidKeys(fn () => $this->repay(1)), 'nothing left to pay');
    }

    public function test_an_lbp_repayment_stores_the_rate_and_the_usd_it_came_to(): void
    {
        $this->creditSale(total: 100);

        $p = $this->repay(4500000, ['currency' => 'LBP']); // 50 USD at 90,000

        $this->assertSame('LBP', $p->currency);
        $this->assertEquals(4500000, $p->amount);
        $this->assertEquals(90000, $p->exchange_rate);
        $this->assertEquals(50, $p->amount_usd);
        $this->assertSame(50.0, $this->owed());

        // The debt is in USD: the rate moving afterwards changes nothing about what is owed.
        Setting::set('exchange_rate', 150000, 'currency', 'float');
        $this->assertSame(50.0, $this->owed());
        $this->assertEquals(50, $this->ledger()->balanceOf($this->customer));
    }

    public function test_paying_the_last_dollars_in_lbp_at_a_rounded_amount_settles_the_receipt(): void
    {
        $sale = $this->creditSale(total: 10.50);

        // 10.50 USD = 945,000 LBP exactly, but the customer hands over 946,000: within one rounding step, not an overpayment.
        $this->repay(946000, ['currency' => 'LBP']);

        $this->assertSame(0.0, $this->outstanding($sale));
        $this->assertSame(0.0, $this->owed());
    }

    public function test_card_and_wish_repayments_are_in_dollars(): void
    {
        $this->creditSale(total: 100);

        $this->assertSame(['currency'], $this->invalidKeys(fn () => $this->repay(900000, ['currency' => 'LBP', 'method' => 'wish'])));
        $this->repay(10, ['method' => 'wish', 'reference' => 'W-1']);
        $this->assertSame('W-1', CustomerPayment::sole()->reference);
    }

    public function test_back_dating_is_for_managers_and_the_future_is_for_nobody(): void
    {
        $this->creditSale(total: 100);
        $yesterday = now()->subDay()->toDateString();

        $this->assertSame(['paid_at'], $this->invalidKeys(fn () => $this->repay(10, ['paid_at' => $yesterday])), 'a cashier cannot back-date');
        $this->assertSame(['paid_at'], $this->invalidKeys(fn () => $this->repay(10, ['paid_at' => now()->addDay()->toDateString(), 'method' => 'bank_transfer'], $this->manager)), 'nobody may future-date');

        $p = $this->repay(10, ['paid_at' => $yesterday, 'method' => 'bank_transfer'], $this->manager);
        $this->assertSame($yesterday, $p->paid_at->toDateString());
        $entry = $p->entries()->sole();
        $this->assertSame($yesterday, $entry->entry_date->toDateString(), 'the ledger line carries the payment date');

        $today = $this->repay(10, ['paid_at' => now()->toDateString()]);
        $this->assertTrue($today->paid_at->isToday());
    }

    public function test_voiding_a_payment_puts_the_money_back_on_the_receipts_it_was_applied_to(): void
    {
        [$c, $a, $b, $d] = $this->rigWithOpeningAndThreeReceipts();
        $payment = $this->repay(140, customer: $c);

        $voided = $this->ledger()->voidPayment($payment, $this->manager, 'handed to the wrong customer');

        $this->assertTrue($voided->isVoided());
        $this->assertSame($this->manager->id, $voided->voided_by);
        $this->assertSame(220.0, $this->owed($c));
        $this->assertSame(50.0, $this->outstanding($b));
        $this->assertSame(30.0, $this->outstanding($d));
        $this->assertSame(100.0, $this->outstanding($a));
        $this->assertTrue($this->ledger()->reconcile($c));
        $this->assertTrue(AuditLog::where('action', 'customer_payment_void')->exists());

        $this->assertSame(['payment'], $this->invalidKeys(fn () => $this->ledger()->voidPayment($payment, $this->manager, 'again')));
    }

    public function test_a_cashier_cannot_void_or_write_off_but_a_manager_can(): void
    {
        $this->creditSale(total: 100);
        $payment = $this->repay(10);

        $this->assertRefusedWith(fn () => $this->ledger()->voidPayment($payment, $this->cashier, 'nope'), AuthorizationException::class);
        $this->assertRefusedWith(fn () => $this->ledger()->writeOff($this->customer, ['amount_usd' => 5, 'reason' => 'x'], $this->cashier), AuthorizationException::class);
        $this->assertSame(90.0, $this->owed());
    }

    public function test_a_write_off_needs_a_reason_and_moves_no_cash(): void
    {
        $sale = $this->creditSale(total: 100);

        $this->assertSame(['reason'], $this->invalidKeys(fn () => $this->ledger()->writeOff($this->customer, ['amount_usd' => 30, 'reason' => '  '], $this->manager)));

        $w = $this->ledger()->writeOff($this->customer, ['amount_usd' => 30, 'reason' => 'Customer left the country'], $this->manager);

        $this->assertTrue($w->isWriteOff());
        $this->assertNull($w->shift_id);
        $this->assertSame(70.0, $this->owed());
        $this->assertSame(70.0, $this->outstanding($sale));
        $this->assertSame(CustomerLedgerEntry::TYPE_WRITE_OFF, $w->entries()->sole()->type);
        $this->assertSame('Customer left the country', AuditLog::where('action', 'customer_write_off')->sole()->new_values['reason']);
        $this->assertSame(0, CustomerPayment::drawerCash()->count(), 'a write-off is not drawer cash');
    }

    public function test_the_statement_runs_a_balance_that_equals_the_ledger_and_the_cached_balance(): void
    {
        $sale = $this->creditSale(total: 100);
        $this->repay(30);
        $this->creditSale(total: 20);
        $this->ledger()->writeOff($this->customer, ['amount_usd' => 10, 'reason' => 'goodwill'], $this->manager);

        $s = $this->ledger()->statement($this->customer, now()->subDays(90), now());

        $this->assertSame(0.0, $s['opening']);
        $this->assertCount(4, $s['rows']);
        $this->assertSame([100.0, 70.0, 90.0, 80.0], array_column($s['rows'], 'balance'));
        $this->assertSame(120.0, $s['debits']);
        $this->assertSame(40.0, $s['credits']);
        $this->assertSame(80.0, $s['closing']);
        $this->assertSame($s['closing'], $this->ledger()->balanceOf($this->customer));
        $this->assertSame($s['closing'], $this->owed());

        // A later window starts from what was owed before it.
        $later = $this->ledger()->statement($this->customer, now()->addDay(), now()->addDays(2));
        $this->assertSame(80.0, $later['opening']);
        $this->assertSame([], $later['rows']);
        $this->assertSame(80.0, $later['closing']);
    }

    public function test_reconcile_reports_a_corrupted_cache_and_fix_rewrites_it_from_the_ledger(): void
    {
        $this->creditSale(total: 100);
        $this->assertSame(0, Artisan::call('customers:reconcile-balances'));

        DB::table('customers')->where('id', $this->customer->id)->update(['balance' => 7]);
        $this->assertFalse($this->ledger()->reconcile($this->customer));

        $this->assertSame(1, Artisan::call('customers:reconcile-balances'), 'read-only report: drift is a failing exit');
        $this->assertStringContainsString('Nadia Khoury', Artisan::output());
        $this->assertSame(7.0, $this->owed(), 'reporting changes nothing');

        $this->assertSame(0, Artisan::call('customers:reconcile-balances', ['--fix' => true]));
        $this->assertSame(100.0, $this->owed());
        $this->assertTrue($this->ledger()->reconcile($this->customer));
        $this->assertTrue(AuditLog::where('action', 'customer_balance_reconcile')->exists());
    }

    public function test_the_backfill_turns_an_old_balance_into_one_opening_entry_and_is_idempotent(): void
    {
        $c = $this->customer(['name' => 'Old', 'phone' => '70123123', 'balance' => 42.5]);
        $owesNothing = $this->customer(['name' => 'Square', 'phone' => '70321321', 'balance' => 0]);
        $credit = $this->customer(['name' => 'Credit', 'phone' => '70456456', 'balance' => -5]);
        $migration = require database_path('migrations/2026_11_13_000003_backfill_customer_opening_balances.php');

        $migration->up();
        $migration->up();

        $entries = CustomerLedgerEntry::where('customer_id', $c->id)->get();
        $this->assertCount(1, $entries);
        $this->assertSame('opening', $entries[0]->type);
        $this->assertEquals(42.5, $entries[0]->amount_usd);
        $this->assertNull($entries[0]->sale_id);
        $this->assertSame(0, CustomerLedgerEntry::where('customer_id', $owesNothing->id)->count());
        $this->assertEquals(-5, CustomerLedgerEntry::where('customer_id', $credit->id)->sole()->amount_usd, 'store credit survives too');
        $this->assertTrue($this->ledger()->reconcile($c));

        // A customer that already has ledger rows is left alone.
        $this->creditSale($c, 10);
        $migration->up();
        $this->assertSame(2, CustomerLedgerEntry::where('customer_id', $c->id)->count());
    }

    public function test_aging_boundaries_due_today_is_not_overdue_and_the_buckets_split_on_the_day(): void
    {
        $days = ['today' => 0, 'late1' => 1, 'late30' => 30, 'late31' => 31, 'late60' => 60, 'late61' => 61, 'late90' => 90, 'late91' => 91];
        foreach ($days as $label => $late) {
            $sale = $this->creditSale(total: 10);
            $this->dueOn($sale, Carbon::today()->subDays($late)->toDateString());
        }
        $this->creditSale(total: 10); // not due yet: due in 30 days

        $totals = $this->ledger()->agingBuckets();

        $this->assertEquals(20, $totals['not_due'], 'due today and due later are both not overdue');
        $this->assertEquals(20, $totals['d1_30'], '1 and 30 days late');
        $this->assertEquals(20, $totals['d31_60'], '31 and 60 days late');
        $this->assertEquals(20, $totals['d61_90'], '61 and 90 days late');
        $this->assertEquals(10, $totals['d90'], '91 days late');
        $this->assertEquals(90, $totals['outstanding']);
        $this->assertEquals(70, $totals['overdue']);

        $row = $this->ledger()->receivables()['customers'][0];
        $this->assertSame(Carbon::today()->subDays(91)->toDateString(), $row['oldest_due']->toDateString());
        $this->assertSame([], array_diff(array_keys($row['aging']), \App\Services\CustomerLedgerService::BUCKETS));
    }

    public function test_the_receivables_list_filters_and_sorts(): void
    {
        $big = $this->customer(['name' => 'Big', 'phone' => '70111000']);
        $late = $this->customer(['name' => 'Late', 'phone' => '70222000']);
        $this->creditSale($big, 500);
        $this->dueOn($this->creditSale($late, 40), Carbon::today()->subDays(5)->toDateString());
        $this->repay(10, customer: $late);

        $all = $this->ledger()->receivables();
        $this->assertSame(['Big', 'Late'], array_map(fn ($r) => $r['customer']->name, $all['customers']));
        $this->assertEquals(530, $all['totals']['outstanding']);

        $overdue = $this->ledger()->receivables(overdueOnly: true);
        $this->assertSame(['Late'], array_map(fn ($r) => $r['customer']->name, $overdue['customers']));
        $this->assertEquals(30, $overdue['customers'][0]['overdue']);

        $this->assertSame(['Late'], array_map(fn ($r) => $r['customer']->name, $this->ledger()->receivables('702')['customers']));
        $this->assertSame(['Late', 'Big'], array_map(fn ($r) => $r['customer']->name, $this->ledger()->receivables(sort: 'overdue')['customers']));
    }

    public function test_the_open_receipts_list_carries_items_due_date_and_what_was_paid(): void
    {
        $sale = $this->creditSale(total: 100, due: now()->addDays(3)->toDateString(), cash: 20);
        $this->repay(30);

        $rows = $this->ledger()->openReceipts($this->customer);

        $this->assertCount(1, $rows);
        $r = $rows[0];
        $this->assertSame($sale->id, $r['sale_id']);
        $this->assertSame(80.0, $r['on_account']);
        $this->assertSame(30.0, $r['paid']);
        $this->assertSame(50.0, $r['outstanding']);
        $this->assertFalse($r['overdue']);
        $this->assertSame(0, $r['days_overdue']);
        $this->assertCount(1, $r['sale']->items);
        $this->assertCount(1, $r['sale']->ledgerEntries->where('type', 'payment'));

        $this->assertSame(Customer::class, get_class($this->customer));
    }

    private function assertRefusedWith(callable $fn, string $class): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            $this->assertInstanceOf($class, $e);

            return;
        }
        $this->fail("Expected {$class}");
    }
}
