<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Setting;

class CurrencyService
{
    public function getRate(): float
    {
        return (float) Setting::get('exchange_rate', 90000);
    }

    public function getRoundingStep(): float
    {
        return (float) Setting::get('lbp_rounding_step', 1000);
    }

    public function usdToLbp(float $usd): float
    {
        $raw = $usd * $this->getRate();
        $step = $this->getRoundingStep();
        return $step > 0 ? round($raw / $step) * $step : round($raw, 2);
    }

    public function lbpToUsd(float $lbp): float
    {
        $rate = $this->getRate();
        return $rate > 0 ? round($lbp / $rate, 4) : 0.0;
    }

    public function updateRate(float $rate, int $userId): void
    {
        $old = $this->getRate();
        Setting::set('exchange_rate', $rate, 'currency', 'float');
        AuditLog::record(
            userId: $userId,
            action: 'rate_change',
            modelType: 'Setting',
            modelId: null,
            old: ['exchange_rate' => $old],
            new: ['exchange_rate' => $rate],
        );
    }

    /**
     * Calculate the change a cashier must return after a customer pays.
     *
     * If $changeUsdOut is null, defaults to giving the full change in USD.
     * If $changeUsdOut is provided, gives that amount in USD and the balance
     * in LBP (rounded to the configured step). The two amounts are guaranteed
     * to be cashier-realisable: change_usd is non-negative, change_lbp is
     * non-negative and snapped to the rounding step.
     *
     * @return array{
     *   change_usd: float, change_lbp: float,
     *   total_change_usd: float, overpaid_usd: float,
     *   split: bool
     * }
     */
    public function calculateChange(float $totalUsd, float $paidUsd, float $paidLbp, ?float $changeUsdOut = null): array
    {
        $paidLbpInUsd = $this->lbpToUsd($paidLbp);
        $totalPaidUsd = $paidUsd + $paidLbpInUsd;
        $overpaid = $totalPaidUsd - $totalUsd;
        $totalChange = max(0.0, $overpaid);

        if ($overpaid <= 0) {
            return [
                'change_usd' => 0.0, 'change_lbp' => 0.0,
                'total_change_usd' => 0.0, 'overpaid_usd' => $overpaid,
                'split' => false,
            ];
        }

        // Default: cashier gives all change in USD.
        if ($changeUsdOut === null) {
            return [
                'change_usd' => round($totalChange, 4),
                'change_lbp' => 0.0,
                'total_change_usd' => round($totalChange, 4),
                'overpaid_usd' => $overpaid,
                'split' => false,
            ];
        }

        // Caller specified an explicit USD portion — clamp and compute the LBP balance.
        $usdPortion = max(0.0, min($changeUsdOut, $totalChange));
        $remainingUsd = $totalChange - $usdPortion;
        $lbpPortion = $this->usdToLbp($remainingUsd);

        return [
            'change_usd' => round($usdPortion, 4),
            'change_lbp' => $lbpPortion,
            'total_change_usd' => round($totalChange, 4),
            'overpaid_usd' => $overpaid,
            'split' => $usdPortion > 0 && $lbpPortion > 0,
        ];
    }

    /**
     * Suggest a denomination breakdown for paying out a USD/LBP amount.
     * Greedy: largest denomination first.
     *
     * @return array<int, array{denom:int|float, count:int, label:string}>
     */
    public function suggestUsdDenominations(float $amount): array
    {
        $denoms = [100, 50, 20, 10, 5, 1];
        return $this->breakdown($amount, $denoms, '$');
    }

    public function suggestLbpDenominations(float $amount): array
    {
        $denoms = [100000, 50000, 20000, 10000, 5000, 1000];
        return $this->breakdown($amount, $denoms, '', ' LBP');
    }

    private function breakdown(float $amount, array $denoms, string $prefix = '', string $suffix = ''): array
    {
        $result = [];
        $remaining = $amount;
        foreach ($denoms as $d) {
            $count = (int) floor($remaining / $d + 1e-9);
            if ($count > 0) {
                $result[] = [
                    'denom' => $d,
                    'count' => $count,
                    'label' => $prefix . number_format($d) . $suffix,
                ];
                $remaining -= $count * $d;
            }
        }
        return $result;
    }
}
