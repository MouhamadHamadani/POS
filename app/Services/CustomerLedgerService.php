<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\CustomerLedgerEntry;
use App\Models\CustomerPayment;
use App\Models\ReturnTransaction;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The debt book. The ONLY writer of `customers.balance`: every change to what a customer owes is a signed row in
 * `customer_ledger_entries` (append-only, USD), and the balance column is a cache of their sum, refreshed here
 * after each write. Nothing else may touch `balance` (customers:reconcile-balances reports any drift).
 *
 * Every public write runs in a transaction, locks the customer row, writes an audit row and refreshes the cache.
 * A receipt's debt is the sum of the entries that carry its sale_id; entries with no sale_id (a balance from before
 * the book, store credit from a return) are one more "pot" the repayments can be applied to.
 */
class CustomerLedgerService
{
    /** An amount owed at or below this is settled: half a cent. */
    public const SETTLED = 0.005;

    /** Aging buckets, in the order they are shown. `not_due` includes "due today" and a pot with no due date. */
    public const BUCKETS = ['not_due', 'd1_30', 'd31_60', 'd61_90', 'd90'];

    public function __construct(
        private readonly CurrencyService $currency,
        private readonly ShiftService $shifts,
    ) {}

    // ------------------------------------------------------------------ writes

    /** A sale put (part of) its total on the customer's tab. Called inside SaleService::process()'s transaction. */
    public function recordCreditSale(Sale $sale, Customer $customer, float $amount): CustomerLedgerEntry
    {
        return DB::transaction(function () use ($sale, $customer, $amount) {
            $customer = $this->lock($customer);
            $entry = $this->post($customer, CustomerLedgerEntry::TYPE_CREDIT_SALE, round($amount, 4), [
                'sale_id' => $sale->id,
                'user_id' => $sale->user_id,
                'notes' => $sale->receipt_number,
            ]);
            $this->refreshBalance($customer);

            AuditLog::record($sale->user_id, 'customer_credit_sale', Customer::class, $customer->id, null, [
                'receipt' => $sale->receipt_number,
                'credit_usd' => round($amount, 4),
                'due_date' => $sale->due_date?->toDateString(),
                'balance' => (float) $customer->balance,
            ]);

            return $entry;
        });
    }

    /**
     * A customer pays down their debt.
     *
     * $data: amount (in `currency`), currency USD|LBP (default USD), method cash|card|wish|bank_transfer|other,
     * paid_at (a date; default now, a past date needs a manager), reference, notes, and optionally
     * allocations [{sale_id|null, amount_usd}] (null sale_id = the opening pot). With no allocations the money goes
     * to the opening pot first, then the receipts by due date, oldest first.
     */
    public function recordPayment(Customer $customer, array $data, User $user): CustomerPayment
    {
        return DB::transaction(function () use ($customer, $data, $user) {
            $customer = $this->lock($customer);

            $method = (string) ($data['method'] ?? CustomerPayment::METHOD_CASH);
            $currency = strtoupper((string) ($data['currency'] ?? 'USD'));
            if (! in_array($method, CustomerPayment::METHODS, true)) {
                throw ValidationException::withMessages(['method' => __('messages.debt_method_invalid')]);
            }
            if (! in_array($currency, ['USD', 'LBP'], true)) {
                throw ValidationException::withMessages(['currency' => __('messages.debt_method_invalid')]);
            }
            // Card and Wish are charged in dollars (Wish is USD in v1).
            if ($currency === 'LBP' && in_array($method, [CustomerPayment::METHOD_CARD, CustomerPayment::METHOD_WISH], true)) {
                throw ValidationException::withMessages(['currency' => __('messages.debt_usd_only')]);
            }

            $amount = round((float) ($data['amount'] ?? 0), 4);
            if ($amount <= 0) {
                throw ValidationException::withMessages(['amount' => __('messages.debt_amount_required')]);
            }

            $rate = $currency === 'LBP' ? $this->currency->getRate() : null;
            $amountUsd = $currency === 'LBP' ? ($rate > 0 ? round($amount / $rate, 4) : 0.0) : $amount;
            $tol = $this->tolerance($currency);

            $targets = $this->openTargets($customer->id);
            $debt = round((float) $targets->sum('outstanding'), 4);
            if ($amountUsd > $debt + $tol) {
                throw ValidationException::withMessages([
                    'amount_usd' => __('messages.payment_exceeds', ['amount' => '$' . number_format($debt, 2)]),
                ]);
            }

            $paidAt = $this->resolvePaidAt($user, $data['paid_at'] ?? null);

            // Where the money is taken: cash needs a drawer unless the store works without shifts. The shift
            // is recorded for every method (the close screen lists repayments by method), only cash is drawer money.
            $shift = $this->shifts->openFor($user);
            if ($method === CustomerPayment::METHOD_CASH && ! $shift && Setting::get('require_shift', true)) {
                throw ValidationException::withMessages(['method' => __('messages.debt_cash_needs_shift')]);
            }

            $allocations = ! empty($data['allocations'])
                ? $this->explicitAllocations($targets, (array) $data['allocations'], $amountUsd, $tol)
                : $this->autoAllocation($targets, $amountUsd);
            $applied = round(array_sum(array_column($allocations, 'amount')), 4);

            $payment = CustomerPayment::create([
                'number' => $this->nextPaymentNumber(),
                'customer_id' => $customer->id,
                'user_id' => $user->id,
                'shift_id' => $shift?->id,
                'kind' => CustomerPayment::KIND_PAYMENT,
                'method' => $method,
                'currency' => $currency,
                'amount' => $amount,
                'exchange_rate' => $rate,
                // What was applied to the debt (a sub-cent / LBP rounding gap is not kept as an overpayment).
                'amount_usd' => $applied,
                'paid_at' => $paidAt,
                'reference' => $this->clean($data['reference'] ?? null),
                'notes' => $this->clean($data['notes'] ?? null),
            ]);

            foreach ($allocations as $a) {
                $this->post($customer, CustomerLedgerEntry::TYPE_PAYMENT, -$a['amount'], [
                    'sale_id' => $a['sale_id'],
                    'customer_payment_id' => $payment->id,
                    'user_id' => $user->id,
                    'entry_date' => $paidAt,
                ]);
            }
            $this->refreshBalance($customer);

            AuditLog::record($user->id, 'customer_payment', CustomerPayment::class, $payment->id, null, [
                'number' => $payment->number,
                'customer_id' => $customer->id,
                'method' => $method,
                'currency' => $currency,
                'amount' => $amount,
                'amount_usd' => $applied,
                'paid_at' => $paidAt->toDateTimeString(),
                'allocations' => $allocations,
                'balance' => (float) $customer->balance,
            ]);

            return $payment->load('customer');
        });
    }

    /** Forgive part of a debt. Managers only, with a reason; no cash moves. $data: amount_usd, reason, allocations. */
    public function writeOff(Customer $customer, array $data, User $user): CustomerPayment
    {
        $this->requireManager($user);

        return DB::transaction(function () use ($customer, $data, $user) {
            $customer = $this->lock($customer);

            $reason = $this->clean($data['reason'] ?? null);
            if ($reason === null) {
                throw ValidationException::withMessages(['reason' => __('messages.debt_reason_required')]);
            }
            $amount = round((float) ($data['amount_usd'] ?? 0), 4);
            if ($amount <= 0) {
                throw ValidationException::withMessages(['amount_usd' => __('messages.debt_amount_required')]);
            }

            $targets = $this->openTargets($customer->id);
            $debt = round((float) $targets->sum('outstanding'), 4);
            if ($amount > $debt + self::SETTLED) {
                throw ValidationException::withMessages([
                    'amount_usd' => __('messages.payment_exceeds', ['amount' => '$' . number_format($debt, 2)]),
                ]);
            }

            $allocations = ! empty($data['allocations'])
                ? $this->explicitAllocations($targets, (array) $data['allocations'], $amount, self::SETTLED)
                : $this->autoAllocation($targets, $amount);
            $applied = round(array_sum(array_column($allocations, 'amount')), 4);

            $writeOff = CustomerPayment::create([
                'number' => $this->nextPaymentNumber(),
                'customer_id' => $customer->id,
                'user_id' => $user->id,
                'shift_id' => null,
                'kind' => CustomerPayment::KIND_WRITE_OFF,
                'method' => CustomerPayment::METHOD_OTHER,
                'currency' => 'USD',
                'amount' => $applied,
                'amount_usd' => $applied,
                'paid_at' => now(),
                'notes' => $reason,
            ]);

            foreach ($allocations as $a) {
                $this->post($customer, CustomerLedgerEntry::TYPE_WRITE_OFF, -$a['amount'], [
                    'sale_id' => $a['sale_id'],
                    'customer_payment_id' => $writeOff->id,
                    'user_id' => $user->id,
                    'notes' => $reason,
                ]);
            }
            $this->refreshBalance($customer);

            AuditLog::record($user->id, 'customer_write_off', CustomerPayment::class, $writeOff->id, null, [
                'number' => $writeOff->number,
                'customer_id' => $customer->id,
                'amount_usd' => $applied,
                'reason' => $reason,
                'allocations' => $allocations,
                'balance' => (float) $customer->balance,
            ]);

            return $writeOff;
        });
    }

    /**
     * Cancel a repayment or a write-off (managers only). Marks it voided and puts the money back on the receipts
     * it was applied to with reversing entries. A cash repayment drops out of its shift's expected cash while that
     * shift is open; a closed shift keeps the figures it stored when it closed.
     */
    public function voidPayment(CustomerPayment $payment, User $user, string $reason): CustomerPayment
    {
        $this->requireManager($user);
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => __('messages.debt_reason_required')]);
        }

        return DB::transaction(function () use ($payment, $user, $reason) {
            $payment = CustomerPayment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($payment->isVoided()) {
                throw ValidationException::withMessages(['payment' => __('messages.debt_payment_already_voided')]);
            }
            $customer = $this->lock($payment->customer_id);

            $payment->update(['voided_at' => now(), 'voided_by' => $user->id, 'void_reason' => $reason]);

            foreach ($payment->entries()->get() as $e) {
                $this->post($customer, CustomerLedgerEntry::TYPE_VOID_REVERSAL, -(float) $e->amount_usd, [
                    'sale_id' => $e->sale_id,
                    'customer_payment_id' => $payment->id,
                    'user_id' => $user->id,
                    'notes' => $reason,
                ]);
            }
            $this->refreshBalance($customer);

            AuditLog::record($user->id, 'customer_payment_void', CustomerPayment::class, $payment->id,
                ['voided' => false], [
                    'number' => $payment->number,
                    'kind' => $payment->kind,
                    'amount_usd' => (float) $payment->amount_usd,
                    'reason' => $reason,
                    'balance' => (float) $customer->balance,
                ]);

            return $payment->fresh();
        });
    }

    /**
     * An approved return refunded to the customer's account. The refund pays down debt: the returned sale's own
     * first, then the other open receipts oldest first, then the opening pot; anything left over is store credit
     * (a negative balance: we owe them), as before the book.
     */
    public function applyReturnCredit(ReturnTransaction $return): void
    {
        $sale = $return->sale;
        if (! $sale || ! $sale->customer_id) {
            return;
        }

        DB::transaction(function () use ($return, $sale) {
            $customer = $this->lock($sale->customer_id);
            $refund = round((float) $return->refund_amount_usd, 4);
            if ($refund <= 0) {
                return;
            }

            // Own receipt first, then the others by due date / age, the opening pot last.
            $targets = $this->openTargets($customer->id)
                ->sortBy(fn ($t) => [$t['sale_id'] === $sale->id ? 0 : ($t['sale_id'] === null ? 2 : 1)])
                ->values();
            $targets = $targets->sortBy(fn ($t, $i) => [$t['sale_id'] === $sale->id ? 0 : ($t['sale_id'] === null ? 2 : 1), $i])->values();

            $allocations = $this->autoAllocation($targets, $refund);
            $used = round(array_sum(array_column($allocations, 'amount')), 4);

            foreach ($allocations as $a) {
                $this->post($customer, CustomerLedgerEntry::TYPE_RETURN_CREDIT, -$a['amount'], [
                    'sale_id' => $a['sale_id'],
                    'return_id' => $return->id,
                    'user_id' => $return->user_id,
                    'notes' => $return->return_number,
                ]);
            }
            $leftover = round($refund - $used, 4);
            if ($leftover > 0.00005) {
                $this->post($customer, CustomerLedgerEntry::TYPE_RETURN_CREDIT, -$leftover, [
                    'sale_id' => null,
                    'return_id' => $return->id,
                    'user_id' => $return->user_id,
                    'notes' => $return->return_number,
                ]);
            }
            $this->refreshBalance($customer);

            AuditLog::record($return->user_id, 'customer_return_credit', ReturnTransaction::class, $return->id, null, [
                'return' => $return->return_number,
                'sale' => $sale->receipt_number,
                'refund_usd' => $refund,
                'against_debt_usd' => $used,
                'store_credit_usd' => max(0.0, $leftover),
                'balance' => (float) $customer->balance,
            ]);
        });
    }

    /**
     * A credit sale is voided: take its debt back off the book. Refused while a live repayment is applied to it
     * (void the payments first). A sale from before the book has no receipt entries: its credit is taken out of
     * the opening pot instead, which is where its debt lives.
     */
    public function reverseCreditSale(Sale $sale, ?int $userId = null): void
    {
        if (! $sale->customer_id || (float) $sale->amount_credit_usd <= 0) {
            return;
        }

        DB::transaction(function () use ($sale, $userId) {
            $customer = $this->lock($sale->customer_id);

            $live = CustomerLedgerEntry::where('sale_id', $sale->id)
                ->whereIn('type', [CustomerLedgerEntry::TYPE_PAYMENT, CustomerLedgerEntry::TYPE_WRITE_OFF])
                ->whereHas('payment', fn ($q) => $q->whereNull('voided_at'))
                ->exists();
            if ($live) {
                throw ValidationException::withMessages(['sale' => __('messages.void_credit_has_payments')]);
            }

            $hasEntries = CustomerLedgerEntry::where('sale_id', $sale->id)->exists();
            $reverse = $hasEntries
                ? round((float) CustomerLedgerEntry::where('sale_id', $sale->id)->sum('amount_usd'), 4)
                : round((float) $sale->amount_credit_usd, 4);

            if (abs($reverse) > 0.00005) {
                $this->post($customer, CustomerLedgerEntry::TYPE_VOID_REVERSAL, -$reverse, [
                    'sale_id' => $hasEntries ? $sale->id : null,
                    'user_id' => $userId,
                    'notes' => $sale->receipt_number,
                ]);
            }
            $this->refreshBalance($customer);

            AuditLog::record($userId, 'credit_sale_void', Sale::class, $sale->id, null, [
                'receipt' => $sale->receipt_number,
                'reversed_usd' => $reverse,
                'balance' => (float) $customer->balance,
            ]);
        });
    }

    /** Change the due date of a credit receipt (managers only). */
    public function changeDueDate(Sale $sale, Carbon $dueDate, User $user): Sale
    {
        $this->requireManager($user);

        if ((float) $sale->amount_credit_usd <= 0) {
            throw ValidationException::withMessages(['due_date' => __('messages.debt_not_a_credit_sale')]);
        }

        $old = $sale->due_date?->toDateString();
        $sale->update(['due_date' => $dueDate->toDateString()]);

        AuditLog::record($user->id, 'due_date_change', Sale::class, $sale->id,
            ['due_date' => $old], ['due_date' => $dueDate->toDateString(), 'receipt' => $sale->receipt_number]);

        return $sale;
    }

    // ------------------------------------------------------------------ reads

    /** How much of the customer's debt is past its due date (due today is not past). */
    public function overdueOf(Customer|int $customer): float
    {
        $id = $customer instanceof Customer ? $customer->id : $customer;
        $today = Carbon::today()->toDateString();

        return round((float) $this->openTargets($id)
            ->filter(fn ($t) => $t['due_date'] !== null && $t['due_date'] < $today)
            ->sum('outstanding'), 4);
    }

    /** What this customer owes according to the ledger (negative = store credit). */
    public function balanceOf(Customer|int $customer): float
    {
        $id = $customer instanceof Customer ? $customer->id : $customer;

        return round((float) CustomerLedgerEntry::where('customer_id', $id)->sum('amount_usd'), 4);
    }

    /** What one receipt still owes (never negative). */
    public function outstandingForSale(Sale|int $sale): float
    {
        $id = $sale instanceof Sale ? $sale->id : $sale;

        return max(0.0, round((float) CustomerLedgerEntry::where('sale_id', $id)->sum('amount_usd'), 4));
    }

    /** The customer's total debt: the open receipts and the opening pot, store credit not netted off. */
    public function debtOf(Customer|int $customer): float
    {
        $id = $customer instanceof Customer ? $customer->id : $customer;

        return round((float) $this->openTargets($id)->sum('outstanding'), 4);
    }

    /**
     * The customer's open debts, one row per receipt that still owes something plus the opening pot, in
     * collection order (pot first, then by due date, oldest first).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function openReceipts(Customer $customer): Collection
    {
        $targets = $this->openTargets($customer->id);
        $saleIds = $targets->pluck('sale_id')->filter()->all();

        $sales = Sale::whereIn('id', $saleIds)
            ->with(['items' => fn ($q) => $q->orderBy('id'), 'items.product' => fn ($q) => $q->withTrashed()->select('id', 'name', 'name_ar'),
                'ledgerEntries' => fn ($q) => $q->whereIn('type', [CustomerLedgerEntry::TYPE_PAYMENT, CustomerLedgerEntry::TYPE_WRITE_OFF])->orderBy('entry_date')->orderBy('id'),
                'ledgerEntries.payment'])
            ->get()->keyBy('id');
        $onAccount = CustomerLedgerEntry::where('customer_id', $customer->id)
            ->where('type', CustomerLedgerEntry::TYPE_CREDIT_SALE)
            ->groupBy('sale_id')->selectRaw('sale_id, SUM(amount_usd) AS usd')->pluck('usd', 'sale_id');

        $today = Carbon::today();

        return $targets->map(function ($t) use ($sales, $onAccount, $today) {
            $sale = $t['sale_id'] ? $sales->get($t['sale_id']) : null;
            $credit = $sale ? round((float) ($onAccount[$sale->id] ?? 0), 4) : round((float) $t['outstanding'], 4);
            $due = $t['due_date'] ? Carbon::parse($t['due_date'])->startOfDay() : null;
            $daysOverdue = $due && $today->gt($due) ? (int) $due->diffInDays($today) : 0;

            return [
                'sale_id' => $t['sale_id'],
                'sale' => $sale,
                'is_pot' => $t['sale_id'] === null,
                'receipt_number' => $t['receipt_number'],
                'date' => $t['sale_date'] ? Carbon::parse($t['sale_date']) : null,
                'due_date' => $due,
                'on_account' => $credit,
                'paid' => round(max(0.0, $credit - (float) $t['outstanding']), 4),
                'outstanding' => round((float) $t['outstanding'], 4),
                'days_overdue' => $daysOverdue,
                'overdue' => $daysOverdue > 0,
                'bucket' => $this->bucketFor($daysOverdue),
            ];
        })->values();
    }

    /**
     * Everything the customer's debt screens show: open receipts, repayments, a statement (default: the last
     * 90 days) and the totals. One call so /debts/{customer} and the customer page cannot drift apart.
     *
     * @return array<string, mixed>
     */
    public function book(Customer $customer, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $to = ($to ?? Carbon::today())->copy();
        $from = ($from ?? $to->copy()->subDays(90))->copy();
        $open = $this->openReceipts($customer);

        return [
            'open' => $open,
            'payments' => $this->payments($customer),
            'statement' => $this->statement($customer, $from, $to),
            'from' => $from,
            'to' => $to,
            'debt' => round((float) $open->sum('outstanding'), 4),
            'overdue' => round((float) $open->where('overdue', true)->sum('outstanding'), 4),
        ];
    }

    /** The customer's repayments and write-offs, newest first, each with the receipts it was applied to. */
    public function payments(Customer $customer): Collection
    {
        return CustomerPayment::where('customer_id', $customer->id)
            ->with(['user:id,name', 'entries' => fn ($q) => $q->whereIn('type', [CustomerLedgerEntry::TYPE_PAYMENT, CustomerLedgerEntry::TYPE_WRITE_OFF]), 'entries.sale:id,receipt_number'])
            ->orderByDesc('paid_at')->orderByDesc('id')
            ->get();
    }

    /**
     * Chronological ledger for a date range with a running balance. `opening` is the balance just before $from,
     * `closing` the balance after the last row, so closing equals the cached balance when the range reaches today.
     *
     * @return array{opening:float,closing:float,debits:float,credits:float,rows:list<array<string,mixed>>}
     */
    public function statement(Customer $customer, Carbon $from, Carbon $to): array
    {
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->endOfDay();

        $opening = round((float) CustomerLedgerEntry::where('customer_id', $customer->id)->where('entry_date', '<', $from)->sum('amount_usd'), 4);
        $entries = CustomerLedgerEntry::where('customer_id', $customer->id)
            ->whereBetween('entry_date', [$from, $to])
            ->with(['sale:id,receipt_number', 'payment:id,number,method,currency,amount'])
            ->orderBy('entry_date')->orderBy('id')->get();

        $running = $opening;
        $debits = $credits = 0.0;
        $rows = [];
        foreach ($entries as $e) {
            $amount = (float) $e->amount_usd;
            $running = round($running + $amount, 4);
            $amount > 0 ? $debits += $amount : $credits += -$amount;
            $rows[] = [
                'date' => $e->entry_date,
                'type' => $e->type,
                'type_label' => $e->typeLabel(),
                'receipt' => $e->sale?->receipt_number,
                'sale_id' => $e->sale_id,
                'payment' => $e->payment?->number,
                'payment_id' => $e->customer_payment_id,
                'debit' => $amount > 0 ? round($amount, 4) : 0.0,
                'credit' => $amount < 0 ? round(-$amount, 4) : 0.0,
                'balance' => $running,
                'notes' => $e->notes,
            ];
        }

        return [
            'opening' => $opening,
            'closing' => $running,
            'debits' => round($debits, 4),
            'credits' => round($credits, 4),
            'rows' => $rows,
        ];
    }

    /**
     * Everyone who owes something: amounts, overdue part, oldest due date, last repayment and the aging split.
     * Filters: `search` (name / phone), `overdue_only`. `sort`: outstanding | overdue (largest first), name.
     *
     * @return array{customers:list<array<string,mixed>>,totals:array<string,float>}
     */
    public function receivables(?string $search = null, bool $overdueOnly = false, string $sort = 'outstanding'): array
    {
        $rows = DB::table('customer_ledger_entries as e')
            ->leftJoin('sales as s', 's.id', '=', 'e.sale_id')
            ->groupBy('e.customer_id', 'e.sale_id')
            ->selectRaw('e.customer_id, e.sale_id, MAX(s.due_date) AS due_date, SUM(e.amount_usd) AS outstanding')
            ->get()
            ->filter(fn ($r) => (float) $r->outstanding > self::SETTLED);

        $lastPaid = DB::table('customer_payments')
            ->where('kind', CustomerPayment::KIND_PAYMENT)->whereNull('voided_at')
            ->groupBy('customer_id')->selectRaw('customer_id, MAX(paid_at) AS last_paid')->pluck('last_paid', 'customer_id');

        $customers = Customer::withTrashed()->whereIn('id', $rows->pluck('customer_id')->unique())->get(['id', 'name', 'phone', 'credit_limit', 'balance'])->keyBy('id');
        $today = Carbon::today();
        $out = [];

        foreach ($rows->groupBy('customer_id') as $customerId => $debts) {
            $customer = $customers->get($customerId);
            if (! $customer) {
                continue;
            }
            if ($search !== null && $search !== '') {
                $needle = mb_strtolower($search);
                if (! str_contains(mb_strtolower((string) $customer->name), $needle) && ! str_contains((string) $customer->phone, $search)) {
                    continue;
                }
            }

            $aging = array_fill_keys(self::BUCKETS, 0.0);
            $oldest = null;
            foreach ($debts as $d) {
                $due = $d->due_date ? Carbon::parse($d->due_date)->startOfDay() : null;
                $days = $due && $today->gt($due) ? (int) $due->diffInDays($today) : 0;
                $aging[$this->bucketFor($days)] += (float) $d->outstanding;
                if ($due && $days > 0 && ($oldest === null || $due->lt($oldest))) {
                    $oldest = $due;
                }
            }
            $outstanding = round(array_sum($aging), 4);
            $overdue = round($outstanding - $aging['not_due'], 4);
            if ($overdueOnly && $overdue <= self::SETTLED) {
                continue;
            }

            $out[] = [
                'customer' => $customer,
                'outstanding' => $outstanding,
                'overdue' => $overdue,
                'oldest_due' => $oldest,
                'last_payment' => ! empty($lastPaid[$customerId]) ? Carbon::parse($lastPaid[$customerId]) : null,
                'aging' => array_map(fn ($v) => round($v, 4), $aging),
            ];
        }

        usort($out, match ($sort) {
            'overdue' => fn ($a, $b) => [$b['overdue'], $b['outstanding']] <=> [$a['overdue'], $a['outstanding']],
            'name' => fn ($a, $b) => strcasecmp((string) $a['customer']->name, (string) $b['customer']->name),
            default => fn ($a, $b) => $b['outstanding'] <=> $a['outstanding'],
        });

        $totals = ['outstanding' => 0.0, 'overdue' => 0.0] + array_fill_keys(self::BUCKETS, 0.0);
        foreach ($out as $r) {
            $totals['outstanding'] += $r['outstanding'];
            $totals['overdue'] += $r['overdue'];
            foreach (self::BUCKETS as $b) {
                $totals[$b] += $r['aging'][$b];
            }
        }

        return ['customers' => $out, 'totals' => array_map(fn ($v) => round($v, 4), $totals)];
    }

    /** The store-wide aging split (what the dashboard tile and the receivables report total). @return array<string,float> */
    public function agingBuckets(): array
    {
        return $this->receivables()['totals'];
    }

    /** Is the cached balance what the ledger says? */
    public function reconcile(Customer $customer): bool
    {
        return abs(round($this->balanceOf($customer), 2) - round((float) $customer->fresh()->balance, 2)) < 0.005;
    }

    /**
     * Customers whose cached balance differs from their ledger. With $fix the cache is rewritten from the ledger
     * (the ledger is the truth) and the repair is audited.
     *
     * @return list<array{customer:Customer,cached:float,ledger:float}>
     */
    public function drift(bool $fix = false): array
    {
        $sums = CustomerLedgerEntry::query()->groupBy('customer_id')->selectRaw('customer_id, SUM(amount_usd) AS usd')->pluck('usd', 'customer_id');
        $out = [];

        foreach (Customer::withTrashed()->get(['id', 'name', 'balance']) as $customer) {
            $ledger = round((float) ($sums[$customer->id] ?? 0), 2);
            $cached = round((float) $customer->balance, 2);
            if (abs($ledger - $cached) < 0.005) {
                continue;
            }
            $out[] = ['customer' => $customer, 'cached' => $cached, 'ledger' => $ledger];

            if ($fix) {
                DB::transaction(function () use ($customer, $ledger, $cached) {
                    $customer = $this->lock($customer);
                    $this->refreshBalance($customer);
                    AuditLog::record(null, 'customer_balance_reconcile', Customer::class, $customer->id,
                        ['balance' => $cached], ['balance' => $ledger]);
                });
            }
        }

        return $out;
    }

    /** Does anyone owe the store anything? Keeps the debts screens reachable after the feature is switched off. */
    public function anyDebt(): bool
    {
        return Customer::withTrashed()->where('balance', '>', self::SETTLED)->exists();
    }

    // ------------------------------------------------------------------ internals

    /**
     * Where the money can go: every receipt (and the opening pot) that still owes more than half a cent, in the
     * order a repayment is applied by default: the pot, then by due date (no date last), then by sale date.
     *
     * @return Collection<int, array{sale_id:?int,outstanding:float,due_date:?string,sale_date:?string,receipt_number:?string}>
     */
    private function openTargets(int $customerId): Collection
    {
        return DB::table('customer_ledger_entries as e')
            ->leftJoin('sales as s', 's.id', '=', 'e.sale_id')
            ->where('e.customer_id', $customerId)
            ->groupBy('e.sale_id')
            ->selectRaw('e.sale_id, MAX(s.due_date) AS due_date, MAX(s.created_at) AS sale_date, MAX(s.receipt_number) AS receipt_number, SUM(e.amount_usd) AS outstanding')
            ->get()
            ->filter(fn ($r) => (float) $r->outstanding > self::SETTLED)
            ->map(fn ($r) => [
                'sale_id' => $r->sale_id !== null ? (int) $r->sale_id : null,
                'outstanding' => round((float) $r->outstanding, 4),
                'due_date' => $r->due_date ? substr((string) $r->due_date, 0, 10) : null,
                'sale_date' => $r->sale_date,
                'receipt_number' => $r->receipt_number,
            ])
            ->sortBy(fn ($t) => [
                $t['sale_id'] === null ? 0 : 1,
                $t['due_date'] === null ? 1 : 0,
                $t['due_date'] ?? '',
                $t['sale_date'] ?? '',
                $t['sale_id'] ?? 0,
            ])
            ->values();
    }

    /**
     * Spread $amount over the targets in order. A receipt left with half a cent or less is settled outright, so
     * no dust stays on the book.
     *
     * @return list<array{sale_id:?int,amount:float}>
     */
    private function autoAllocation(Collection $targets, float $amount): array
    {
        $remaining = round($amount, 4);
        $out = [];

        foreach ($targets as $t) {
            if ($remaining <= 0.00005) {
                break;
            }
            $take = min($remaining, $t['outstanding']);
            if ($t['outstanding'] - $take <= self::SETTLED) {
                $take = $t['outstanding'];
            }
            $out[] = ['sale_id' => $t['sale_id'], 'amount' => round($take, 4)];
            $remaining = round($remaining - $take, 4);
        }

        return $out;
    }

    /**
     * The cashier's own split. Each line must name an open receipt (or the opening pot, sale_id null) and stay
     * within what it owes; together they must make up the payment.
     *
     * @param  array<int, array{sale_id:mixed,amount_usd:mixed}>  $given
     * @return list<array{sale_id:?int,amount:float}>
     */
    private function explicitAllocations(Collection $targets, array $given, float $amountUsd, float $tol): array
    {
        $byId = $targets->keyBy(fn ($t) => $t['sale_id'] ?? 'pot');
        $sum = [];
        $entered = 0.0;

        foreach ($given as $row) {
            $amt = round((float) ($row['amount_usd'] ?? 0), 4);
            if ($amt <= 0) {
                continue;
            }
            $saleId = ! empty($row['sale_id']) ? (int) $row['sale_id'] : null;
            $target = $byId->get($saleId ?? 'pot');
            if (! $target) {
                throw ValidationException::withMessages(['allocations' => __('messages.debt_alloc_target')]);
            }
            $key = $saleId ?? 'pot';
            $sum[$key] = round(($sum[$key] ?? 0) + $amt, 4);
            $entered += $amt;
            if ($sum[$key] > $target['outstanding'] + $tol) {
                throw ValidationException::withMessages(['allocations' => __('messages.debt_alloc_over', [
                    'receipt' => $target['receipt_number'] ?? __('messages.debt_opening_pot'),
                    'amount' => '$' . number_format($target['outstanding'], 2),
                ])]);
            }
        }

        if (abs($entered - $amountUsd) > $tol) {
            throw ValidationException::withMessages(['allocations' => __('messages.debt_alloc_sum', [
                'allocated' => '$' . number_format($entered, 2),
                'amount' => '$' . number_format($amountUsd, 2),
            ])]);
        }

        $out = [];
        foreach ($sum as $key => $amt) {
            $target = $byId->get($key);
            if ($target['outstanding'] - $amt <= self::SETTLED) {
                $amt = $target['outstanding'];
            }
            $out[] = ['sale_id' => $target['sale_id'], 'amount' => round(min($amt, $target['outstanding']), 4)];
        }

        return $out;
    }

    /** Write one ledger row. @param array{sale_id?:?int,customer_payment_id?:?int,return_id?:?int,user_id?:?int,notes?:?string,entry_date?:Carbon} $link */
    private function post(Customer $customer, string $type, float $amountUsd, array $link = []): CustomerLedgerEntry
    {
        return CustomerLedgerEntry::create([
            'customer_id' => $customer->id,
            'type' => $type,
            'sale_id' => $link['sale_id'] ?? null,
            'customer_payment_id' => $link['customer_payment_id'] ?? null,
            'return_id' => $link['return_id'] ?? null,
            'amount_usd' => round($amountUsd, 4),
            'entry_date' => $link['entry_date'] ?? now(),
            'user_id' => $link['user_id'] ?? null,
            'notes' => $link['notes'] ?? null,
        ]);
    }

    /** Rewrite the cached balance from the ledger. The only statement in the app that writes customers.balance. */
    private function refreshBalance(Customer $customer): void
    {
        $balance = round($this->balanceOf($customer), 2);
        DB::table('customers')->where('id', $customer->id)->update(['balance' => $balance]);
        $customer->setAttribute('balance', $balance);
        $customer->syncOriginalAttribute('balance');
    }

    private function lock(Customer|int $customer): Customer
    {
        $id = $customer instanceof Customer ? $customer->id : $customer;

        return Customer::withTrashed()->whereKey($id)->lockForUpdate()->firstOrFail();
    }

    /** Slack when comparing a payment with what is owed: half a cent, or one LBP rounding step for a payment in LBP. */
    private function tolerance(string $currency): float
    {
        if ($currency !== 'LBP') {
            return self::SETTLED;
        }
        $rate = $this->currency->getRate();

        return max(self::SETTLED, $rate > 0 ? $this->currency->getRoundingStep() / $rate : 0.0);
    }

    /** Today by default; a past date only for a manager; never a future one. */
    private function resolvePaidAt(User $user, mixed $date): Carbon
    {
        if ($date === null || $date === '') {
            return now();
        }
        $d = Carbon::parse($date);
        if ($d->isToday()) {
            return now();
        }
        if ($d->isFuture()) {
            throw ValidationException::withMessages(['paid_at' => __('messages.debt_paid_at_future')]);
        }
        if (! $user->isManagerial()) {
            throw ValidationException::withMessages(['paid_at' => __('messages.debt_paid_at_past')]);
        }

        return $d->setTime(12, 0);
    }

    private function requireManager(User $user): void
    {
        if (! $user->isManagerial()) {
            throw new AuthorizationException(__('messages.debt_manager_only'));
        }
    }

    /** Days overdue -> aging bucket. Due today is not overdue. */
    public function bucketFor(int $daysOverdue): string
    {
        return match (true) {
            $daysOverdue <= 0 => 'not_due',
            $daysOverdue <= 30 => 'd1_30',
            $daysOverdue <= 60 => 'd31_60',
            $daysOverdue <= 90 => 'd61_90',
            default => 'd90',
        };
    }

    private function nextPaymentNumber(): string
    {
        $prefix = Setting::get('payment_prefix', 'PAY-' . date('Y') . '-');
        $counter = (int) Setting::get('payment_counter', 0) + 1;
        Setting::set('payment_counter', $counter, 'numbering', 'int');

        return $prefix . str_pad((string) $counter, 5, '0', STR_PAD_LEFT);
    }

    private function clean(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
