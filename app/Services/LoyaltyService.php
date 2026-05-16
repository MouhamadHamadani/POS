<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\LoyaltyTransaction;
use App\Models\Sale;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;

class LoyaltyService
{
    public function pointsPerDollar(): float
    {
        return (float) Setting::get('points_per_dollar', 1);
    }

    public function redemptionRate(): int
    {
        return (int) Setting::get('redemption_rate', 100); // 100 points = $1 default
    }

    public function redeemValue(int $points): float
    {
        $rate = $this->redemptionRate();
        return $rate > 0 ? round($points / $rate, 4) : 0.0;
    }

    public function awardForSale(Sale $sale, Customer $customer): void
    {
        $earned = (int) floor((float) $sale->total_usd * $this->pointsPerDollar());

        DB::transaction(function () use ($sale, $customer, $earned) {
            $redeemed = (int) $sale->loyalty_points_redeemed;

            if ($redeemed > 0) {
                $customer->decrement('loyalty_points', $redeemed);
                LoyaltyTransaction::create([
                    'customer_id' => $customer->id,
                    'sale_id' => $sale->id,
                    'type' => LoyaltyTransaction::TYPE_REDEEM,
                    'points' => -$redeemed,
                    'balance_after' => $customer->fresh()->loyalty_points,
                    'note' => 'Redeemed at checkout',
                ]);
            }

            if ($earned > 0) {
                $customer->increment('loyalty_points', $earned);
                LoyaltyTransaction::create([
                    'customer_id' => $customer->id,
                    'sale_id' => $sale->id,
                    'type' => LoyaltyTransaction::TYPE_EARN,
                    'points' => $earned,
                    'balance_after' => $customer->fresh()->loyalty_points,
                    'note' => 'Earned on sale ' . $sale->receipt_number,
                ]);
                $sale->update(['loyalty_points_earned' => $earned]);
            }

            $this->updateTier($customer->fresh());
        });
    }

    public function updateTier(Customer $customer): void
    {
        $points = $customer->loyalty_points;
        $bronze = (int) Setting::get('bronze_threshold', 0);
        $silver = (int) Setting::get('silver_threshold', 500);
        $gold = (int) Setting::get('gold_threshold', 2000);

        $tier = match (true) {
            $points >= $gold => 'gold',
            $points >= $silver => 'silver',
            default => 'bronze',
        };

        if ($customer->loyalty_tier !== $tier) {
            $customer->update(['loyalty_tier' => $tier]);
        }
    }
}
