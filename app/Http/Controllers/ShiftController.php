<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use App\Models\Shift;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ShiftController extends Controller
{
    public function showOpen(): View|RedirectResponse
    {
        $open = $this->currentShift();
        if ($open) {
            return redirect('/pos')->with('info', 'A shift is already open.');
        }
        return view('shifts.open');
    }

    public function open(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'opening_cash_usd' => 'required|numeric|min:0',
            'opening_cash_lbp' => 'required|numeric|min:0',
            'denominations' => 'nullable|array',
            'denominations.usd' => 'nullable|array',
            'denominations.lbp' => 'nullable|array',
            'notes' => 'nullable|string|max:500',
        ]);

        if ($this->currentShift()) {
            return redirect('/pos')->with('warning', 'A shift is already open.');
        }

        $denoms = $this->normalizeDenominations($data['denominations'] ?? null);
        [$denomUsdTotal, $denomLbpTotal] = $this->totalsFromDenominations($denoms);

        // If the cashier entered denominations, trust those numbers over the typed totals.
        $usdTotal = $denoms['usd'] ? $denomUsdTotal : (float) $data['opening_cash_usd'];
        $lbpTotal = $denoms['lbp'] ? $denomLbpTotal : (float) $data['opening_cash_lbp'];

        Shift::create([
            'user_id' => $request->user()->id,
            'opened_at' => now(),
            'opening_cash_usd' => $usdTotal,
            'opening_cash_lbp' => $lbpTotal,
            'opening_denominations' => $denoms['usd'] || $denoms['lbp'] ? $denoms : null,
            'status' => Shift::STATUS_OPEN,
            'notes' => $data['notes'] ?? null,
        ]);

        return redirect('/pos')->with('success', 'Shift opened.');
    }

    public function showClose(): View|RedirectResponse
    {
        $shift = $this->currentShift();
        if (!$shift) {
            return redirect('/shifts/open')->with('warning', 'No shift is open.');
        }

        $totals = $this->shiftTotals($shift);

        return view('shifts.close', compact('shift', 'totals'));
    }

    public function close(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'closing_cash_usd' => 'required|numeric|min:0',
            'closing_cash_lbp' => 'required|numeric|min:0',
            'denominations' => 'nullable|array',
            'denominations.usd' => 'nullable|array',
            'denominations.lbp' => 'nullable|array',
            'notes' => 'nullable|string|max:500',
        ]);

        $shift = $this->currentShift();
        if (!$shift) {
            return redirect('/')->with('warning', 'No shift is open.');
        }

        $denoms = $this->normalizeDenominations($data['denominations'] ?? null);
        [$denomUsdTotal, $denomLbpTotal] = $this->totalsFromDenominations($denoms);

        $closingUsd = $denoms['usd'] ? $denomUsdTotal : (float) $data['closing_cash_usd'];
        $closingLbp = $denoms['lbp'] ? $denomLbpTotal : (float) $data['closing_cash_lbp'];
        $data['closing_cash_usd'] = $closingUsd;
        $data['closing_cash_lbp'] = $closingLbp;

        $totals = $this->shiftTotals($shift);
        $expectedUsd = (float) $shift->opening_cash_usd + $totals['cash_in_usd'] - $totals['cash_out_usd'];
        $expectedLbp = (float) $shift->opening_cash_lbp + $totals['cash_in_lbp'] - $totals['cash_out_lbp'];

        $shift->update([
            'closed_at' => now(),
            'closing_cash_usd' => $data['closing_cash_usd'],
            'closing_cash_lbp' => $data['closing_cash_lbp'],
            'closing_denominations' => $denoms['usd'] || $denoms['lbp'] ? $denoms : null,
            'expected_cash_usd' => $expectedUsd,
            'expected_cash_lbp' => $expectedLbp,
            'variance_usd' => $data['closing_cash_usd'] - $expectedUsd,
            'variance_lbp' => $data['closing_cash_lbp'] - $expectedLbp,
            'status' => Shift::STATUS_CLOSED,
            'closed_by' => $request->user()->id,
            'notes' => $data['notes'] ?? $shift->notes,
        ]);

        return redirect('/shifts/open')->with('success', 'Shift closed. Variance USD: ' . round($shift->variance_usd, 2));
    }

    private function currentShift(): ?Shift
    {
        return Shift::where('user_id', auth()->id())
            ->where('status', Shift::STATUS_OPEN)
            ->latest('opened_at')
            ->first();
    }

    /**
     * Standardize the denominations payload to a stable shape
     * {usd: [denom => count], lbp: [denom => count]}, dropping zero counts.
     */
    private function normalizeDenominations(?array $raw): array
    {
        $out = ['usd' => [], 'lbp' => []];
        if (!$raw) return $out;
        foreach (['usd', 'lbp'] as $cur) {
            foreach ((array) ($raw[$cur] ?? []) as $denom => $count) {
                $c = (int) $count;
                if ($c > 0) $out[$cur][(int) $denom] = $c;
            }
        }
        return $out;
    }

    /**
     * @return array{0: float, 1: float}  [usd_total, lbp_total]
     */
    private function totalsFromDenominations(array $denoms): array
    {
        $usd = 0; $lbp = 0;
        foreach ($denoms['usd'] ?? [] as $d => $c) $usd += (int) $d * (int) $c;
        foreach ($denoms['lbp'] ?? [] as $d => $c) $lbp += (int) $d * (int) $c;
        return [(float) $usd, (float) $lbp];
    }

    private function shiftTotals(Shift $shift): array
    {
        $sales = Sale::where('shift_id', $shift->id)
            ->where('status', Sale::STATUS_COMPLETED)
            ->get();

        $cashInUsd = (float) $sales->sum('amount_tendered_usd') - (float) $sales->sum('change_usd');
        $cashInLbp = (float) $sales->sum('amount_tendered_lbp') - (float) $sales->sum('change_lbp');

        return [
            'sales_count' => $sales->count(),
            'total_revenue_usd' => round((float) $sales->sum('total_usd'), 2),
            'cash_in_usd' => round($cashInUsd, 2),
            'cash_in_lbp' => round($cashInLbp, 0),
            'cash_out_usd' => 0,
            'cash_out_lbp' => 0,
        ];
    }
}
