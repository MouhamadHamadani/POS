<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Setting;
use App\Models\Shift;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class SaleService
{
    public function __construct(
        private readonly CurrencyService $currency,
        private readonly InventoryService $inventory,
        private readonly LoyaltyService $loyalty,
    ) {}

    /**
     * Process a sale atomically: validate, snapshot prices, deduct stock,
     * collect payment, calculate change, award loyalty points.
     *
     * Cart line shape:
     *   ['product_id'=>int, 'qty'=>float, 'unit_price'=>float,
     *    'discount_pct'=>float, 'discount_amount'=>float, 'note'=>string|null]
     *
     * Payment shape:
     *   ['method'=>string, 'amount_usd'=>float, 'amount_lbp'=>float,
     *    'amount_card'=>float, 'amount_credit'=>float,
     *    'card_type'=>?string, 'card_reference'=>?string,
     *    'loyalty_points_redeemed'=>int]
     */
    public function process(
        array $cart,
        array $payment,
        int $userId,
        int $shiftId,
        ?int $customerId = null,
        ?string $notes = null,
    ): Sale {
        if (empty($cart)) {
            throw ValidationException::withMessages(['cart' => 'Cart is empty.']);
        }

        $shift = Shift::findOrFail($shiftId);
        if (!$shift->isOpen()) {
            throw new RuntimeException('Cannot record a sale against a closed shift.');
        }

        $customer = $customerId ? Customer::find($customerId) : null;

        return DB::transaction(function () use ($cart, $payment, $userId, $shift, $customer, $notes) {
            $exchangeRate = $this->currency->getRate();
            $totals = $this->totalsFromCart($cart, $customer);

            // Pre-flight stock check: reject the whole sale before any DB write
            // if any tracked product would be oversold (including duplicate cart lines).
            $requested = [];
            foreach ($totals['lines'] as $line) {
                $p = $line['product'];
                if (!$p->track_stock || $p->type === Product::TYPE_BUNDLE) {
                    continue;
                }
                $requested[$p->id] = ($requested[$p->id] ?? 0) + (float) $line['qty'];
            }
            foreach ($requested as $pid => $needed) {
                $product = Product::find($pid);
                if ($product && (float) $product->stock_qty < $needed) {
                    throw ValidationException::withMessages([
                        'stock' => sprintf(
                            'Insufficient stock for "%s": %s available, %s requested.',
                            $product->name,
                            rtrim(rtrim(number_format((float) $product->stock_qty, 4, '.', ''), '0'), '.'),
                            rtrim(rtrim(number_format($needed, 4, '.', ''), '0'), '.'),
                        ),
                    ]);
                }
            }

            $loyaltyRedeemDiscount = $this->loyalty->redeemValue((int) ($payment['loyalty_points_redeemed'] ?? 0));
            $grandTotal = max(0, $totals['total'] - $loyaltyRedeemDiscount);

            $tendUsd = (float) ($payment['amount_usd'] ?? 0);
            $tendLbp = (float) ($payment['amount_lbp'] ?? 0);
            $card = (float) ($payment['amount_card'] ?? 0);
            $credit = (float) ($payment['amount_credit'] ?? 0);

            $cashAsUsd = $tendUsd + $this->currency->lbpToUsd($tendLbp);
            $totalPaid = $cashAsUsd + $card + $credit;

            if ($totalPaid + 0.001 < $grandTotal) {
                throw ValidationException::withMessages(['payment' => 'Insufficient payment.']);
            }

            if ($credit > 0) {
                if (!$customer) {
                    throw ValidationException::withMessages(['customer' => 'A customer is required for credit sales.']);
                }
                if (!$customer->hasAvailableCredit($credit)) {
                    throw ValidationException::withMessages(['customer' => 'Customer credit limit exceeded.']);
                }
            }

            $change = $this->currency->calculateChange($grandTotal - $card - $credit, $tendUsd, $tendLbp);

            $sale = Sale::create([
                'receipt_number' => $this->nextReceiptNumber(),
                'customer_id' => $customer?->id,
                'user_id' => $userId,
                'shift_id' => $shift->id,
                'subtotal_usd' => $totals['subtotal'],
                'discount_amount_usd' => $totals['discount'] + $loyaltyRedeemDiscount,
                'tax_amount_usd' => $totals['tax'],
                'total_usd' => $grandTotal,
                'total_lbp' => $this->currency->usdToLbp($grandTotal),
                'exchange_rate' => $exchangeRate,
                'payment_method' => $payment['method'] ?? Sale::METHOD_CASH_USD,
                'amount_tendered_usd' => $tendUsd ?: null,
                'amount_tendered_lbp' => $tendLbp ?: null,
                'amount_card_usd' => $card,
                'amount_credit_usd' => $credit,
                'change_usd' => $change['change_usd'],
                'change_lbp' => $change['change_lbp'],
                'loyalty_points_redeemed' => (int) ($payment['loyalty_points_redeemed'] ?? 0),
                'card_type' => $payment['card_type'] ?? null,
                'card_reference' => $payment['card_reference'] ?? null,
                'status' => Sale::STATUS_COMPLETED,
                'notes' => $notes,
            ]);

            foreach ($totals['lines'] as $line) {
                SaleItem::create([
                    'sale_id' => $sale->id,
                    'product_id' => $line['product']->id,
                    'product_name' => $line['product']->name,
                    'product_sku' => $line['product']->sku,
                    'qty' => $line['qty'],
                    'unit_price_usd' => $line['unit_price'],
                    'cost_usd' => $line['product']->cost_usd,
                    'discount_pct' => $line['discount_pct'],
                    'discount_amount_usd' => $line['discount_amount'],
                    'tax_rate' => $line['tax_rate'],
                    'tax_amount_usd' => $line['tax_amount'],
                    'line_total_usd' => $line['line_total'],
                    'is_taxable' => $line['product']->is_taxable,
                    'note' => $line['note'] ?? null,
                ]);

                $this->inventory->deductForSale($line['product'], (float) $line['qty'], $userId, $sale->id);
            }

            if ($customer && $credit > 0) {
                $customer->increment('balance', $credit);
            }

            if ($customer) {
                $this->loyalty->awardForSale($sale, $customer);
            }

            AuditLog::record($userId, 'sale', Sale::class, $sale->id, null, ['receipt' => $sale->receipt_number, 'total' => $grandTotal]);

            return $sale->load('items', 'customer');
        });
    }

    /**
     * Compute subtotal/discount/tax/total from a raw cart without persisting anything.
     * Pure function — safe to call from controllers for live cart preview.
     */
    public function totalsFromCart(array $cart, ?Customer $customer = null): array
    {
        $lines = [];
        $subtotal = 0;
        $discount = 0;
        $tax = 0;

        foreach ($cart as $row) {
            $product = $row['product'] ?? Product::find($row['product_id']);
            if (!$product) {
                throw ValidationException::withMessages(['cart' => "Product #{$row['product_id']} not found."]);
            }

            $qty = (float) ($row['qty'] ?? 1);
            $unitPrice = (float) ($row['unit_price'] ?? $product->priceForGroup($customer->customer_group ?? 'retail'));
            $discountPct = (float) ($row['discount_pct'] ?? 0);
            $discountAmt = (float) ($row['discount_amount'] ?? 0);

            $gross = $qty * $unitPrice;
            $lineDiscount = $discountAmt + ($gross * $discountPct / 100);
            $net = $gross - $lineDiscount;

            $taxRate = 0.0;
            $taxAmt = 0.0;
            if ($product->is_taxable && !($customer?->tax_exempt) && $product->tax) {
                $taxRate = (float) $product->tax->rate;
                if ($product->tax->is_inclusive) {
                    $taxAmt = $net - ($net / (1 + $taxRate));
                } else {
                    $taxAmt = $net * $taxRate;
                }
            }

            $lineTotal = $product->tax && $product->tax->is_inclusive ? $net : $net + $taxAmt;

            $subtotal += $gross;
            $discount += $lineDiscount;
            $tax += $taxAmt;

            $lines[] = [
                'product' => $product,
                'qty' => $qty,
                'unit_price' => $unitPrice,
                'discount_pct' => $discountPct,
                'discount_amount' => $lineDiscount,
                'tax_rate' => $taxRate,
                'tax_amount' => round($taxAmt, 4),
                'line_total' => round($lineTotal, 4),
                'note' => $row['note'] ?? null,
            ];
        }

        return [
            'lines' => $lines,
            'subtotal' => round($subtotal, 4),
            'discount' => round($discount, 4),
            'tax' => round($tax, 4),
            'total' => round($subtotal - $discount + $tax, 4),
        ];
    }

    public function voidSale(Sale $sale, int $userId, ?string $reason = null): Sale
    {
        return DB::transaction(function () use ($sale, $userId, $reason) {
            if ($sale->status === Sale::STATUS_VOIDED) {
                throw new RuntimeException('Sale is already voided.');
            }

            foreach ($sale->items as $item) {
                if ($item->product) {
                    $this->inventory->adjust(
                        $item->product,
                        (float) $item->qty,
                        \App\Models\InventoryAdjustment::TYPE_RETURN,
                        $userId,
                        reason: 'sale_void',
                        referenceType: 'sale',
                        referenceId: $sale->id,
                    );
                }
            }

            if ($sale->customer && $sale->amount_credit_usd > 0) {
                $sale->customer->decrement('balance', (float) $sale->amount_credit_usd);
            }

            $sale->update([
                'status' => Sale::STATUS_VOIDED,
                'voided_at' => now(),
                'voided_by' => $userId,
                'notes' => trim(($sale->notes ?? '') . "\nVOID: " . ($reason ?? '')),
            ]);

            AuditLog::record($userId, 'void', Sale::class, $sale->id, ['status' => 'completed'], ['status' => 'voided', 'reason' => $reason]);

            return $sale->fresh();
        });
    }

    private function nextReceiptNumber(): string
    {
        $prefix = Setting::get('receipt_prefix', 'REC-' . date('Y') . '-');
        $counter = (int) Setting::get('receipt_counter', 0) + 1;
        Setting::set('receipt_counter', $counter, 'numbering', 'int');
        return $prefix . str_pad((string) $counter, 5, '0', STR_PAD_LEFT);
    }
}
