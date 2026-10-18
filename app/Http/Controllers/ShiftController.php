<?php

namespace App\Http\Controllers;

use App\Models\Shift;
use App\Services\ShiftService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ShiftController extends Controller
{
    public function __construct(private readonly ShiftService $shifts) {}

    public function showOpen(): View|RedirectResponse
    {
        $open = $this->currentShift();
        if ($open) {
            return redirect('/pos')->with('info', __('messages.shift_already_open'));
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
            return redirect('/pos')->with('warning', __('messages.shift_already_open'));
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

        return redirect('/pos')->with('success', __('messages.shift_opened'));
    }

    public function showClose(): View|RedirectResponse
    {
        $shift = $this->currentShift();
        if (!$shift) {
            return redirect('/shifts/open')->with('warning', __('messages.no_shift_open'));
        }

        $totals = $this->shifts->totals($shift);
        $expenses = $this->shifts->drawerExpenses($shift);

        return view('shifts.close', compact('shift', 'totals', 'expenses'));
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
            return redirect('/')->with('warning', __('messages.no_shift_open'));
        }

        $denoms = $this->normalizeDenominations($data['denominations'] ?? null);
        [$denomUsdTotal, $denomLbpTotal] = $this->totalsFromDenominations($denoms);

        $closingUsd = $denoms['usd'] ? $denomUsdTotal : (float) $data['closing_cash_usd'];
        $closingLbp = $denoms['lbp'] ? $denomLbpTotal : (float) $data['closing_cash_lbp'];
        $data['closing_cash_usd'] = $closingUsd;
        $data['closing_cash_lbp'] = $closingLbp;

        $totals = $this->shifts->totals($shift);
        $expectedUsd = $totals['expected_usd'];
        $expectedLbp = $totals['expected_lbp'];

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

        return redirect('/shifts/open')->with('success', __('messages.shift_closed', ['variance' => round($shift->variance_usd, 2)]));
    }

    private function currentShift(): ?Shift
    {
        return auth()->user() ? $this->shifts->openFor(auth()->user()) : null;
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
}
