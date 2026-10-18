<?php

namespace App\Services;

use App\Models\CashMovement;
use App\Models\Expense;
use App\Models\ReturnTransaction;
use App\Models\Sale;
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
     * currency it was handled in. Customer payments are not counted here.
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

        $inUsd = round($salesUsd + $moved(CashMovement::TYPE_IN, 'usd'), 2);
        $inLbp = round($salesLbp + $moved(CashMovement::TYPE_IN, 'lbp'), 0);
        $outUsd = round($moved(CashMovement::TYPE_OUT, 'usd') + $refunded('cash_usd', 'refund_amount_usd'), 2);
        $outLbp = round($moved(CashMovement::TYPE_OUT, 'lbp') + $refunded('cash_lbp', 'refund_amount_lbp'), 0);

        return [
            'sales_count' => $sales->count(),
            'total_revenue_usd' => round((float) $sales->sum('total_usd'), 2),
            'cash_in_usd' => $inUsd,
            'cash_in_lbp' => $inLbp,
            'cash_out_usd' => $outUsd,
            'cash_out_lbp' => $outLbp,
            'expected_usd' => round((float) $shift->opening_cash_usd + $inUsd - $outUsd, 4),
            'expected_lbp' => round((float) $shift->opening_cash_lbp + $inLbp - $outLbp, 2),
        ];
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
