<?php

namespace Tests\Feature\Sales;

use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Shift;
use App\Models\User;

/** Just enough sale data for the Sales History tests. */
trait MakesSales
{
    private int $saleSeq = 0;

    protected function cashier(): User
    {
        return User::factory()->create(['role' => User::ROLE_CASHIER]);
    }

    protected function sale(User $cashier, array $attrs = []): Sale
    {
        $shift = Shift::where('user_id', $cashier->id)->first() ?? Shift::create([
            'user_id' => $cashier->id, 'opened_at' => now(),
            'opening_cash_usd' => 0, 'opening_cash_lbp' => 0, 'status' => Shift::STATUS_OPEN,
        ]);

        $createdAt = $attrs['created_at'] ?? null;
        unset($attrs['created_at']);

        $sale = Sale::create($attrs + [
            'receipt_number' => 'REC-T-'.str_pad((string) ++$this->saleSeq, 4, '0', STR_PAD_LEFT),
            'user_id' => $cashier->id,
            'shift_id' => $shift->id,
            'subtotal_usd' => 10, 'tax_amount_usd' => 0, 'total_usd' => 10, 'total_lbp' => 1000000,
            'exchange_rate' => 100000,
            'payment_method' => Sale::METHOD_CASH_USD,
            'status' => Sale::STATUS_COMPLETED,
        ]);

        if ($createdAt) {
            $sale->forceFill(['created_at' => $createdAt])->save();
        }

        return $sale;
    }

    protected function item(Sale $sale, string $name = 'Test Item', array $attrs = []): SaleItem
    {
        $product = Product::factory()->create(['name' => $name]);

        return SaleItem::create($attrs + [
            'sale_id' => $sale->id, 'product_id' => $product->id, 'product_name' => $name,
            'qty' => 2, 'unit_price_usd' => 5, 'cost_usd' => 2, 'line_total_usd' => 10,
            'tax_rate' => 0.11, 'tax_amount_usd' => 1.10,
        ]);
    }
}
