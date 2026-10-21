<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class SaleService
{
    /** Half of the last stored decimal — slack for comparing 4-dp USD amounts. */
    private const EPS = 0.00005;

    /**
     * Slack for comparing what was paid with what is due: half a cent. A sale's total carries four decimals
     * (per-line VAT), but the till shows it rounded to cents and that is what a customer pays on the card
     * machine or in the Wish app, so a payment up to half a cent either side of the stored total is the total.
     * The extra 0.0001 keeps an exact half cent (and a JS float) on the right side of the line.
     * The till's own slack (resources/views/pos/sell.blade.php, PaymentModalParityTest) is the same number.
     */
    private const PAY_TOLERANCE = 0.0051;

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
     * Payment shape (flat: each kind of money at most once; USD and LBP cash are two fields):
     *   ['amount_usd'=>float, 'amount_lbp'=>float,                       cash tendered, by currency
     *    'amount_card'=>float, 'card_type'=>?string, 'card_reference'=>?string,
     *    'amount_wish'=>float, 'wish_reference'=>?string,                USD
     *    'amount_credit'=>float,                                         on account (loyalty feature)
     *    'change_usd_out'=>?float, 'loyalty_points_redeemed'=>int]
     * `method` is no longer read: payment_method is derived from the amounts (Sale::deriveMethod).
     * Non-cash can never exceed the total and never causes change; cash covers what is left.
     *
     * $shiftId is the cashier's open shift, or null for a shift-less sale. A shift-less sale
     * is legal only while Settings > POS > "Require open shift to sell" is off; with it on,
     * null is refused here too so the service never relies on the controller having checked.
     */
    public function process(
        array $cart,
        array $payment,
        User $user,
        ?int $shiftId,
        ?int $customerId = null,
        ?string $notes = null,
        ?string $priceTier = null,
        array $cartDiscount = [],
        string $channel = Sale::CHANNEL_ONSITE,
        ?string $channelSource = null,
    ): Sale {
        if (empty($cart)) {
            throw ValidationException::withMessages(['cart' => __('messages.cart_empty')]);
        }

        $shift = null;
        if ($shiftId !== null) {
            $shift = Shift::findOrFail($shiftId);
            if (!$shift->isOpen()) {
                throw new RuntimeException(__('messages.shift_closed_sale'));
            }
        } elseif (Setting::get('require_shift', true)) {
            throw new RuntimeException(__('messages.no_open_shift'));
        }

        $customer = $customerId ? Customer::find($customerId) : null;
        $tier = $this->resolvePriceTier($user, $priceTier, $customer);
        $userId = $user->id;

        // Channel is bookkeeping: it prices, stocks, taxes and awards nothing differently. The
        // request's own rules (the feature gate, source only with online) are Sale::resolveChannel's;
        // this just refuses to store a value the enum does not have.
        $channel = $channel === Sale::CHANNEL_ONLINE ? Sale::CHANNEL_ONLINE : Sale::CHANNEL_ONSITE;
        $channelSource = $channel === Sale::CHANNEL_ONLINE && in_array($channelSource, Sale::CHANNEL_SOURCES, true) ? $channelSource : null;

        return DB::transaction(function () use ($cart, $payment, $user, $userId, $shift, $customer, $notes, $tier, $cartDiscount, $channel, $channelSource) {
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
                        'stock' => __('messages.insufficient_stock', [
                            'product' => $product->localName(),
                            'available' => rtrim(rtrim(number_format((float) $product->stock_qty, 4, '.', ''), '0'), '.'),
                            'requested' => rtrim(rtrim(number_format($needed, 4, '.', ''), '0'), '.'),
                        ]),
                    ]);
                }
            }

            $loyaltyRedeemDiscount = $this->loyalty->redeemValue((int) ($payment['loyalty_points_redeemed'] ?? 0));
            $grandTotal = max(0, $totals['total'] - $loyaltyRedeemDiscount);

            $tendUsd = (float) ($payment['amount_usd'] ?? 0);
            $tendLbp = (float) ($payment['amount_lbp'] ?? 0);
            $card = (float) ($payment['amount_card'] ?? 0);
            $wish = (float) ($payment['amount_wish'] ?? 0);
            $credit = (float) ($payment['amount_credit'] ?? 0);

            $cashAsUsd = $tendUsd + $this->currency->lbpToUsd($tendLbp);
            $nonCash = $card + $wish + $credit;
            $totalPaid = $cashAsUsd + $nonCash;

            if ($totalPaid + self::PAY_TOLERANCE < $grandTotal) {
                throw ValidationException::withMessages(['payment' => __('messages.payment_insufficient')]);
            }

            // Card, Wish and on-account are money that moved somewhere else (the card machine, the
            // Wish app, the customer's tab). They are exact or less, so they can never create change
            // out of the drawer: only cash can be over-tendered.
            if ($nonCash > $grandTotal + self::PAY_TOLERANCE) {
                throw ValidationException::withMessages(['payment' => __('messages.payment_noncash_exceeds')]);
            }

            // What is left for cash to cover. Cash tendered when nothing is left to cover is a
            // keying mistake, not a tip: refuse it rather than quietly handing it back as change.
            $cashDue = max(0.0, $grandTotal - $nonCash);
            if ($cashDue <= self::PAY_TOLERANCE && ($tendUsd > 0 || $tendLbp > 0)) {
                throw ValidationException::withMessages(['payment' => __('messages.payment_cash_not_needed')]);
            }

            if ($credit > 0) {
                if (!$customer) {
                    throw ValidationException::withMessages(['customer' => __('messages.credit_customer_required')]);
                }
                if (!$customer->hasAvailableCredit($credit)) {
                    throw ValidationException::withMessages(['customer' => __('messages.credit_limit_exceeded')]);
                }
            }

            $changeUsdOut = array_key_exists('change_usd_out', $payment) && $payment['change_usd_out'] !== null
                ? (float) $payment['change_usd_out']
                : null;
            // Change is worked out against the cash due only. (It used to be total - card - credit,
            // which went negative when card + credit exceeded the total and told the cashier to hand
            // back drawer cash for a card overpayment.)
            $change = $this->currency->calculateChange($cashDue, $tendUsd, $tendLbp, $changeUsdOut);

            $sale = Sale::create([
                'receipt_number' => $this->nextReceiptNumber(),
                'customer_id' => $customer?->id,
                'user_id' => $userId,
                'shift_id' => $shift?->id,
                'price_tier' => $tier,
                'channel' => $channel,
                'channel_source' => $channelSource,
                'subtotal_usd' => $totals['subtotal'],
                'discount_amount_usd' => $totals['discount'] + $loyaltyRedeemDiscount,
                'cart_discount_usd' => $totals['cart_discount'],
                'tax_amount_usd' => $totals['tax'],
                'total_usd' => $grandTotal,
                'total_lbp' => $this->currency->usdToLbp($grandTotal),
                'exchange_rate' => $exchangeRate,
                // Derived from the amounts; the client's own `payment.method` is not read.
                'payment_method' => Sale::deriveMethod($tendUsd, $tendLbp, $card, $wish, $credit),
                'amount_tendered_usd' => $tendUsd ?: null,
                'amount_tendered_lbp' => $tendLbp ?: null,
                'amount_card_usd' => $card,
                'amount_credit_usd' => $credit,
                'change_usd' => $change['change_usd'],
                'change_lbp' => $change['change_lbp'],
                'loyalty_points_redeemed' => (int) ($payment['loyalty_points_redeemed'] ?? 0),
                // The till always sends a default card type; it only means something on a card payment.
                'card_type' => $card > 0 ? ($payment['card_type'] ?? null) : null,
                'card_reference' => $card > 0 ? ($payment['card_reference'] ?? null) : null,
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

            // The itemised record, beside the flat columns above (which stay the drawer's ledger).
            $paymentLines = $this->paymentRows($payment, $tendUsd, $tendLbp, $card, $wish, $credit, (float) $grandTotal);
            foreach ($paymentLines as $row) {
                $sale->payments()->create(Arr::except($row, 'kind'));
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
                'channel' => $channel,
                'channel_source' => $channelSource,
                'payment_method' => $sale->payment_method,
                // Per-method amounts as paid (cash USD / LBP are what was tendered; the change is on the sale).
                'payments' => collect($paymentLines)->mapWithKeys(fn ($l) => [$l['kind'] => $l['amount']])->all(),
                'discount_total' => $totals['discount'],
                'cart_discount' => $totals['cart_discount'],
                'max_line_discount_pct' => $totals['max_line_discount_pct'],
            ]);

            return $sale->load('items', 'customer', 'payments');
        });
    }

    /**
     * The sale_payments rows for one payment: a line per kind of money that was actually used.
     * Cash lines carry what was TENDERED in that currency (change stays on the sale); LBP is also
     * stored as its USD value at today's rate. Wish is USD in v1.
     *
     * @return list<array{sale_id?:int,method:string,currency:string,amount:float,amount_usd:float,reference:?string,card_type:?string,kind:string}>
     */
    private function paymentRows(array $payment, float $tendUsd, float $tendLbp, float $card, float $wish, float $credit, float $grandTotal): array
    {
        $ref = fn (string $key) => ($v = trim((string) ($payment[$key] ?? ''))) !== '' ? $v : null;
        $lines = [];

        if ($tendUsd > 0) {
            $lines[] = SalePayment::line(SalePayment::METHOD_CASH, 'USD', $tendUsd, $tendUsd);
        }
        if ($tendLbp > 0) {
            $lines[] = SalePayment::line(SalePayment::METHOD_CASH, 'LBP', $tendLbp, $this->currency->lbpToUsd($tendLbp));
        }
        if ($card > 0) {
            $lines[] = SalePayment::line(SalePayment::METHOD_CARD, 'USD', $card, $card, $ref('card_reference'), $payment['card_type'] ?? null);
        }
        if ($wish > 0) {
            $lines[] = SalePayment::line(SalePayment::METHOD_WISH, 'USD', $wish, $wish, $ref('wish_reference'));
        }
        if ($credit > 0) {
            $lines[] = SalePayment::line(SalePayment::METHOD_CREDIT, 'USD', $credit, $credit);
        }

        // Paid to the cent by card or Wish with no cash to absorb the sub-cent gap: the machine was charged
        // $1.50 for a $1.4985 sale. `amount` keeps what the machine / the app was charged (that is what the
        // shift's reconciliation panel adds up); `amount_usd`, which the reports add up, is booked at what the
        // sale actually came to, so the lines sum to the total and a day of card sales never shows phantom cash.
        if ($tendUsd <= 0 && $tendLbp <= 0) {
            $gap = round($grandTotal - ($card + $wish + $credit), 4);
            if ($gap !== 0.0 && abs($gap) <= self::PAY_TOLERANCE) {
                $adjustable = array_keys(array_filter($lines, fn ($l) => in_array($l['method'], [SalePayment::METHOD_CARD, SalePayment::METHOD_WISH], true)));
                if ($adjustable !== []) {
                    usort($adjustable, fn ($a, $b) => $lines[$b]['amount'] <=> $lines[$a]['amount']);
                    $lines[$adjustable[0]]['amount_usd'] = round($lines[$adjustable[0]]['amount_usd'] + $gap, 4);
                }
            }
        }

        return $lines;
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
                throw ValidationException::withMessages(['cart' => __('messages.product_missing', ['id' => $row['product_id']])]);
            }

            $qty = (float) ($row['qty'] ?? 1);
            $unitPrice = $product->priceForGroup($tier);
            $discountPct = (float) ($row['discount_pct'] ?? 0);
            $discountAmt = (float) ($row['discount_amount'] ?? 0);

            $gross = round($qty * $unitPrice, 4);
            $lineDiscount = round($discountAmt + $gross * $discountPct / 100, 4);

            if ($lineDiscount > 0 && !$product->allow_discount) {
                throw ValidationException::withMessages([
                    "cart.$i.discount" => __('pos.errors.discount_not_allowed', ['product' => $product->localName()]),
                ]);
            }
            if ($lineDiscount > $gross + self::EPS) {
                throw ValidationException::withMessages([
                    "cart.$i.discount" => __('pos.errors.discount_exceeds_line', ['product' => $product->localName()]),
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
                        'product' => $line['product']->localName(),
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
                throw new RuntimeException(__('messages.sale_already_voided'));
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
