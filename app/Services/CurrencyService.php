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
     * Calculate change for a sale paid with USD + LBP cash.
     *
     * @return array{change_usd: float, change_lbp: float, overpaid_usd: float}
     */
    public function calculateChange(float $totalUsd, float $paidUsd, float $paidLbp): array
    {
        $paidLbpInUsd = $this->lbpToUsd($paidLbp);
        $totalPaidUsd = $paidUsd + $paidLbpInUsd;
        $overpaid = $totalPaidUsd - $totalUsd;

        if ($overpaid <= 0) {
            return ['change_usd' => 0.0, 'change_lbp' => 0.0, 'overpaid_usd' => $overpaid];
        }

        return [
            'change_usd' => round($overpaid, 4),
            'change_lbp' => $this->usdToLbp($overpaid),
            'overpaid_usd' => $overpaid,
        ];
    }
}
