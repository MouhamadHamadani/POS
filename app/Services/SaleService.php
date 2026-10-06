<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class SaleService
{
    /** Half of the last stored decimal — slack for comparing 4-dp USD amounts. */
    private const EPS = 0.00005;

    public function __construct(
        private readonly CurrencyService $currency,
        private readonly InventoryService $inventory,
        private readonly LoyaltyService $loyalty,
    ) {}

    /**
     * Process a sale atomically: validate, snapshot prices, deduct stock,
     * collect payment, calculate change, award loyalty points.
     *
     * Cart line shape. There is deliberately no price: the server prices every
     * line from the product row and the resolved price list.
     *   ['product_id'=>int, 'qty'=>float,
     *    'discount_pct'=>float, 'discount_amount'=>float, 'note'=>string|null]
     *
     * $priceTier is what the client asked for; null means "the customer's
     * group, else retail" (see resolvePriceTier() for who may override it).
     * $cartDiscount is ['pct'=>float, 'amount'=>float] (see totalsFromCart()).
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
        User $user,
        int $shiftId,
        ?int $customerId = null,
        ?string $notes = null,
        ?string $priceTier = null,
        array $cartDiscount = [],
    ): Sale {
        if (empty($cart)) {
            throw ValidationException::withMessages(['cart' => 'Cart is empty.']);
        }

        $shift = Shift::findOrFail($shiftId);
        if (!$shift->isOpen()) {
            throw new RuntimeException('Cannot record a sale against a closed shift.');
        }

        $customer = $customerId ? Customer::find($customerId) : null;
        $tier = $this->resolvePriceTier($user, $priceTier, $customer);
        $userId = $user->id;

        return DB::transaction(function () use ($cart, $payment, $user, $userId, $shift, $customer, $notes, $tier, $cartDiscount) {
            $exchangeRate = $this->currency->getRate();
            $totals = $this->totalsFromCart($cart, $customer, $tier, $cartDiscount);
            $this->assertWithinDiscountLimit($totals, $user);

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

            $changeUsdOut = array_key_exists('change_usd_out', $payment) && $payment['change_usd_out'] !== null
                ? (float) $payment['change_usd_out']
                : null;
            $change = $this->currency->calculateChange(
                $grandTotal - $card - $credit, $tendUsd, $tendLbp, $changeUsdOut
            );

            $sale = Sale::create([
                'receipt_number' => $this->nextReceiptNumber(),
                'customer_id' => $customer?->id,
                'user_id' => $userId,
                'shift_id' => $shift->id,
                'price_tier' => $tier,
                'subtotal_usd' => $totals['subtotal'],
                'discount_amount_usd' => $totals['discount'] + $loyaltyRedeemDiscount,
                'cart_discount_usd' => $totals['cart_discount'],
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
                    'price_tier' => $tier,
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

            AuditLog::record($userId, 'sale', Sale::class, $sale->id, null, [
                'receipt' => $sale->receipt_number,
                'total' => $grandTotal,
                'price_tier' => $tier,
                'discount_total' => $totals['discount'],
                'cart_discount' => $totals['cart_discount'],
                'max_line_discount_pct' => $totals['max_line_discount_pct'],
            ]);

            return $sale->load('items', 'customer');
        });
    }

    /**
     * Which price list a sale is rung up on.
     *
     * No tier requested → the attached customer's group, else retail. A
     * requested tier is honoured for managerial users; a cashier may only use
     * one that differs from the customer's group while the store lets them
     * (setting `cashier_can_change_price_tier`).
     */
    public function resolvePriceTier(User $user, ?string $requested, ?Customer $customer): string
    {
        $customerTier = in_array($customer?->customer_group, Customer::GROUPS, true)
            ? $customer->customer_group
            : Customer::GROUP_RETAIL;

        if ($requested === null) {
            return $customerTier;
        }

        if ($requested !== $customerTier && !$user->canChangePriceTier()) {
            throw ValidationException::withMessages(['price_tier' => __('pos.errors.price_tier_locked')]);
        }

        return $requested;
    }

    /**
     * Compute subtotal/discount/tax/total from a raw cart without persisting anything.
     * Pure function — safe to call from controllers for live cart preview.
     *
     * Every amount is rounded to the 4 stored decimals per line *before* it is
     * summed, so the sale's totals equal the sum of the sale_items rows exactly.
     * resources/views/pos/sell.blade.php (computeCart) mirrors this arithmetic
     * step for step so the on-screen total matches — change both or neither.
     *
     * The unit price is always `Product::priceForGroup($priceTier)`; any price
     * the client sent is ignored. $priceTier defaults to the customer's group.
     *
     * $cartDiscount is ['pct' => % of the discountable net, 'amount' => flat $]
     * (both optional, summed if both are given). It is split across the lines
     * that allow discounts, in proportion to each line's net after its own
     * discount, and folded into each line's discount_amount so per-line VAT,
     * reports and returns need no special case. The rounding remainder lands on
     * the largest line, so the shares add up to the cart discount exactly.
     */
    public function totalsFromCart(
        array $cart,
        ?Customer $customer = null,
        ?string $priceTier = null,
        array $cartDiscount = [],
    ): array {
        $tier = $priceTier ?? ($customer->customer_group ?? Customer::GROUP_RETAIL);

        // Pass 1: price and line-level discount, which the cart discount builds on.
        $lines = [];
        foreach (array_values($cart) as $i => $row) {
            $product = $row['product'] ?? Product::find($row['product_id']);
            if (!$product) {
                throw ValidationException::withMessages(['cart' => "Product #{$row['product_id']} not found."]);
            }

            $qty = (float) ($row['qty'] ?? 1);
            $unitPrice = $product->priceForGroup($tier);
            $discountPct = (float) ($row['discount_pct'] ?? 0);
            $discountAmt = (float) ($row['discount_amount'] ?? 0);

            $gross = round($qty * $unitPrice, 4);
            $lineDiscount = round($discountAmt + $gross * $discountPct / 100, 4);

            if ($lineDiscount > 0 && !$product->allow_discount) {
                throw ValidationException::withMessages([
                    "cart.$i.discount" => __('pos.errors.discount_not_allowed', ['product' => $product->name]),
                ]);
            }
            if ($lineDiscount > $gross + self::EPS) {
                throw ValidationException::withMessages([
                    "cart.$i.discount" => __('pos.errors.discount_exceeds_line', ['product' => $product->name]),
                ]);
            }

            $lines[$i] = [
                'product' => $product,
                'qty' => $qty,
                'unit_price' => $unitPrice,
                'discount_pct' => $discountPct,
                'gross' => $gross,
                'line_discount' => $lineDiscount,
                'net' => round($gross - $lineDiscount, 4),
                'share' => 0.0,
                'note' => $row['note'] ?? null,
            ];
        }

        // Cart-level discount, allocated over the lines that may be discounted.
        $pool = 0.0;
        foreach ($lines as $l) {
            if ($l['product']->allow_discount) {
                $pool += $l['net'];
            }
        }
        $pool = round($pool, 4);

        $cartDisc = round(
            $pool * (float) ($cartDiscount['pct'] ?? 0) / 100 + (float) ($cartDiscount['amount'] ?? 0),
            4
        );
        if ($cartDisc > $pool + self::EPS) {
            throw ValidationException::withMessages(['cart_discount' => __('pos.errors.cart_discount_exceeds')]);
        }
        if ($cartDisc > 0) {
            $largest = null;
            $allocated = 0.0;
            foreach ($lines as $i => $l) {
                if (!$l['product']->allow_discount || $l['net'] <= 0) {
                    continue;
                }
                $lines[$i]['share'] = round($cartDisc * $l['net'] / $pool, 4);
                $allocated += $lines[$i]['share'];
                if ($largest === null || $l['net'] > $lines[$largest]['net']) {
                    $largest = $i;
                }
            }
            $lines[$largest]['share'] = round($lines[$largest]['share'] + ($cartDisc - $allocated), 4);
        }

        // Pass 2: tax and totals, on the net after both discounts.
        $out = [];
        $subtotal = $discount = $tax = $total = $maxPct = 0.0;
        foreach ($lines as $l) {
            $product = $l['product'];
            $disc = round($l['line_discount'] + $l['share'], 4);
            $net = round($l['net'] - $l['share'], 4);

            $taxRate = 0.0;
            $taxAmt = 0.0;
            if ($product->is_taxable && !($customer?->tax_exempt) && $product->tax) {
                $taxRate = (float) $product->tax->rate;
                $taxAmt = round(
                    $product->tax->is_inclusive ? $net - ($net / (1 + $taxRate)) : $net * $taxRate,
                    4
                );
            }

            $lineTotal = round($product->tax && $product->tax->is_inclusive ? $net : $net + $taxAmt, 4);
            $effectivePct = $l['gross'] > 0 ? $disc / $l['gross'] * 100 : 0.0;

            $subtotal += $l['gross'];
            $discount += $disc;
            $tax += $taxAmt;
            $total += $lineTotal;
            $maxPct = max($maxPct, $effectivePct);

            $out[] = [
                'product' => $product,
                'qty' => $l['qty'],
                'unit_price' => $l['unit_price'],
                'discount_pct' => $l['discount_pct'],
                'discount_amount' => $disc,
                'discount_effective_pct' => $effectivePct,
                'tax_rate' => $taxRate,
                'tax_amount' => $taxAmt,
                'line_total' => $lineTotal,
                'note' => $l['note'],
            ];
        }

        return [
            'lines' => $out,
            'subtotal' => round($subtotal, 4),
            'discount' => round($discount, 4),
            'cart_discount' => $cartDisc,
            'max_line_discount_pct' => round($maxPct, 2),
            'tax' => round($tax, 4),
            // Sum of line totals, not subtotal - discount + tax: an
            // inclusive-VAT line already carries its tax inside $net, so
            // adding $tax again overcharges the customer. Summing the lines
            // is also correct for a cart that mixes inclusive and exclusive
            // items, which a single cart-wide rule cannot be.
            'total' => round($total, 4),
        ];
    }

    /**
     * Reject a cart whose discount on any line (its own plus its share of the
     * cart discount, as a % of the line's gross) is above what this user may give.
     */
    private function assertWithinDiscountLimit(array $totals, User $user): void
    {
        $limit = $user->maxDiscountPct();

        foreach ($totals['lines'] as $i => $line) {
            if ($line['discount_effective_pct'] > $limit + 0.01) {
                throw ValidationException::withMessages([
                    "cart.$i.discount" => __('pos.errors.discount_limit', [
                        'product' => $line['product']->name,
                        'pct' => round($line['discount_effective_pct'], 2),
                        'limit' => round($limit, 2),
                    ]),
                ]);
            }
        }
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
