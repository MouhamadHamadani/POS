<?php

namespace App\Services;

use App\Models\CashMovement;
use App\Models\CustomerPayment;
use App\Models\Expense;
use App\Models\ReturnTransaction;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * What a shift's drawer should hold. The close screen, the stored expected/variance
 * figures and the shift summary all read this one calculation.
 */
class ShiftService
{
    public function openFor(User $user): ?Shift
    {
        return Shift::where('user_id', $user->id)
            ->where('status', Shift::STATUS_OPEN)
            ->latest('opened_at')
            ->first();
    }

    /**
     * Cash is counted per physical currency. Sales add what was tendered less the
     * change handed back; a cash movement adds to or takes from the drawer in the
     * currency it was handled in; a cash repayment of a customer's debt adds to the drawer in
     * the currency it was taken in, in the shift that took it (voided ones excluded).
     *
     * The drawer holds the cash of every sale that was not voided, refunded sales
     * included, less the cash refunds paid back for those sales (counted as cash out, in
     * the currency they were paid in: cash_usd refunds in USD, cash_lbp refunds in LBP;
     * account and exchange refunds move no cash). A refund is netted against the shift
     * that rang the sale, so a fully refunded cash sale nets to zero and a partly
     * refunded one to what was kept. sales_count and revenue still leave out a fully
     * refunded sale.
     *
     * Not decided (BUG-028): a refund paid in a LATER shift than the sale, or out of
     * another drawer, still moves no cash in the shift that pays it.
     *
     * @return array<string, float|int>
     */
    public function totals(Shift $shift): array
    {
        $takings = Sale::where('shift_id', $shift->id)
            ->whereIn('status', [Sale::STATUS_COMPLETED, Sale::STATUS_PARTIAL_REFUND, Sale::STATUS_REFUNDED])
            ->get();
        $sales = $takings->where('status', '!=', Sale::STATUS_REFUNDED);

        $salesUsd = (float) $takings->sum('amount_tendered_usd') - (float) $takings->sum('change_usd');
        $salesLbp = (float) $takings->sum('amount_tendered_lbp') - (float) $takings->sum('change_lbp');

        $refunds = ReturnTransaction::whereIn('sale_id', $takings->pluck('id'))
            ->where('status', ReturnTransaction::STATUS_COMPLETED)
            ->get(['refund_method', 'refund_amount_usd', 'refund_amount_lbp']);
        $refunded = fn (string $method, string $column): float => (float) $refunds->where('refund_method', $method)->sum($column);

        $moves = CashMovement::where('shift_id', $shift->id)->get(['type', 'currency', 'amount_usd', 'amount_lbp']);
        $moved = fn (string $type, string $currency): float => (float) $moves
            ->filter(fn ($m) => $m->type === $type && strtolower($m->currency) === $currency)
            ->sum($currency === 'usd' ? 'amount_usd' : 'amount_lbp');

        // Customers paying down what they owe, in cash: USD into the USD drawer, LBP into the LBP drawer, in the shift
        // that took it. Card, Wish and bank repayments never touch the drawer, and a voided repayment is not here.
        $repayments = CustomerPayment::drawerCash()->where('shift_id', $shift->id)->get(['currency', 'amount']);
        $debtUsd = (float) $repayments->where('currency', 'USD')->sum('amount');
        $debtLbp = (float) $repayments->where('currency', 'LBP')->sum('amount');

        $inUsd = round($salesUsd + $moved(CashMovement::TYPE_IN, 'usd') + $debtUsd, 2);
        $inLbp = round($salesLbp + $moved(CashMovement::TYPE_IN, 'lbp') + $debtLbp, 0);
        $outUsd = round($moved(CashMovement::TYPE_OUT, 'usd') + $refunded('cash_usd', 'refund_amount_usd'), 2);
        $outLbp = round($moved(CashMovement::TYPE_OUT, 'lbp') + $refunded('cash_lbp', 'refund_amount_lbp'), 0);

        return [
            'sales_count' => $sales->count(),
            'total_revenue_usd' => round((float) $sales->sum('total_usd'), 2),
            'cash_in_usd' => $inUsd,
            'cash_in_lbp' => $inLbp,
            'debt_cash_usd' => round($debtUsd, 2),
            'debt_cash_lbp' => round($debtLbp, 0),
            'cash_out_usd' => $outUsd,
            'cash_out_lbp' => $outLbp,
            'expected_usd' => round((float) $shift->opening_cash_usd + $inUsd - $outUsd, 4),
            'expected_lbp' => round((float) $shift->opening_cash_lbp + $inLbp - $outLbp, 2),
        ];
    }

    /**
     * What the shift took by card and by Wish, for checking against the card machine and the
     * Wish app. Read-only: none of it is in the drawer, so totals() never sees it. Same sales as
     * the drawer counts (completed, partly refunded and refunded: the money did move).
     *
     * @return array{card_usd:float,wish_usd:float}
     */
    public function nonCashTotals(Shift $shift): array
    {
        $sums = SalePayment::query()
            ->join('sales', 'sales.id', '=', 'sale_payments.sale_id')
            ->where('sales.shift_id', $shift->id)
            ->whereIn('sales.status', [Sale::STATUS_COMPLETED, Sale::STATUS_PARTIAL_REFUND, Sale::STATUS_REFUNDED])
            ->whereIn('sale_payments.method', [SalePayment::METHOD_CARD, SalePayment::METHOD_WISH])
            ->groupBy('sale_payments.method')
            // `amount`, not `amount_usd`: what the card machine / the Wish app was charged (both are USD today).
            ->selectRaw('sale_payments.method AS method, SUM(sale_payments.amount) AS total')
            ->pluck('total', 'method');

        return [
            'card_usd' => round((float) ($sums[SalePayment::METHOD_CARD] ?? 0), 2),
            'wish_usd' => round((float) ($sums[SalePayment::METHOD_WISH] ?? 0), 2),
        ];
    }

    /**
     * Debt repayments this shift took, by method: for the read-only panel on the close screen. Cash is already
     * inside totals() (it is in the drawer); card, Wish and bank transfers are listed for reconciling elsewhere.
     * Voided repayments are left out.
     *
     * @return Collection<int, array{method:string,currency:string,count:int,amount:float,usd:float}>
     */
    public function debtCollected(Shift $shift): Collection
    {
        return CustomerPayment::query()
            ->where('shift_id', $shift->id)->where('kind', CustomerPayment::KIND_PAYMENT)->whereNull('voided_at')
            ->groupBy('method', 'currency')->orderBy('method')
            ->selectRaw('method, currency, COUNT(*) AS n, SUM(amount) AS amount, SUM(amount_usd) AS usd')
            ->get()
            ->map(fn ($r) => [
                'method' => $r->method, 'currency' => $r->currency, 'count' => (int) $r->n,
                'amount' => round((float) $r->amount, 4), 'usd' => round((float) $r->usd, 4),
            ]);
    }

    /** Expenses paid out of this shift's drawer, oldest first. */
    public function drawerExpenses(Shift $shift): Collection
    {
        return Expense::with('category:id,name,name_ar')
            ->where('shift_id', $shift->id)
            ->where('paid_from', Expense::PAID_DRAWER)
            ->orderBy('id')
            ->get();
    }
}
