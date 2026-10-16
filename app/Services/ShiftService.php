<?php

namespace App\Services;

use App\Models\CashMovement;
use App\Models\Expense;
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
     * currency it was handled in. Refunds and customer payments are not counted here.
     *
     * @return array<string, float|int>
     */
    public function totals(Shift $shift): array
    {
        $sales = Sale::where('shift_id', $shift->id)
            ->where('status', Sale::STATUS_COMPLETED)
            ->get();

        $salesUsd = (float) $sales->sum('amount_tendered_usd') - (float) $sales->sum('change_usd');
        $salesLbp = (float) $sales->sum('amount_tendered_lbp') - (float) $sales->sum('change_lbp');

        $moves = CashMovement::where('shift_id', $shift->id)->get(['type', 'currency', 'amount_usd', 'amount_lbp']);
        $moved = fn (string $type, string $currency): float => (float) $moves
            ->filter(fn ($m) => $m->type === $type && strtolower($m->currency) === $currency)
            ->sum($currency === 'usd' ? 'amount_usd' : 'amount_lbp');

        $inUsd = round($salesUsd + $moved(CashMovement::TYPE_IN, 'usd'), 2);
        $inLbp = round($salesLbp + $moved(CashMovement::TYPE_IN, 'lbp'), 0);
        $outUsd = round($moved(CashMovement::TYPE_OUT, 'usd'), 2);
        $outLbp = round($moved(CashMovement::TYPE_OUT, 'lbp'), 0);

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
