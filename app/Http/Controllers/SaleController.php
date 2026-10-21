<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\Shift;
use App\Services\SaleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SaleController extends Controller
{
    public function __construct(private readonly SaleService $sales) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'cart' => 'required|array|min:1',
            'cart.*.product_id' => 'required|integer|exists:products,id',
            'cart.*.qty' => 'required|numeric|min:0.0001',
            // No cart.*.unit_price: the server prices every line (SaleService).
            'cart.*.discount_pct' => 'nullable|numeric|min:0|max:100',
            'cart.*.discount_amount' => 'nullable|numeric|min:0',
            'cart.*.note' => 'nullable|string|max:255',

            'payment' => 'required|array',
            // `method` is accepted for old clients and ignored: SaleService derives payment_method
            // from the amounts below (cash USD / LBP, card, Wish, on account — any mix).
            'payment.method' => 'nullable|string',
            'payment.amount_usd' => 'nullable|numeric|min:0',
            'payment.amount_lbp' => 'nullable|numeric|min:0',
            'payment.amount_card' => 'nullable|numeric|min:0',
            'payment.amount_wish' => 'nullable|numeric|min:0',
            'payment.amount_credit' => 'nullable|numeric|min:0',
            'payment.card_type' => 'nullable|string|max:50',
            'payment.card_reference' => 'nullable|string|max:100',
            'payment.wish_reference' => 'nullable|string|max:100',
            'payment.loyalty_points_redeemed' => 'nullable|integer|min:0',
            'payment.change_usd_out' => 'nullable|numeric|min:0',

            'price_tier' => ['nullable', Rule::in(Customer::GROUPS)],
            'cart_discount_pct' => 'nullable|numeric|min:0|max:100',
            'cart_discount_amount' => 'nullable|numeric|min:0',

            'customer_id' => 'nullable|integer|exists:customers,id',
            'notes' => 'nullable|string|max:1000',

            // On-site or an online order rung up by hand; see Sale::resolveChannel() for the rules.
            'channel' => ['nullable', Rule::in(Sale::CHANNELS)],
            'channel_source' => ['nullable', Rule::in(Sale::CHANNEL_SOURCES)],
        ]);
        [$channel, $channelSource] = Sale::resolveChannel($data['channel'] ?? null, $data['channel_source'] ?? null);

        // Attaching a customer, paying on account and redeeming points are the loyalty
        // feature's. The sale itself is core and goes through without them; only the
        // customer-bound parts of the request are refused, so a till that has loyalty
        // switched off under it says so instead of quietly pricing the sale differently.
        if (! feature('loyalty') && (
            ($data['customer_id'] ?? null) !== null
            || (float) ($data['payment']['amount_credit'] ?? 0) > 0
            || (int) ($data['payment']['loyalty_points_redeemed'] ?? 0) > 0
        )) {
            return response()->json(['error' => __('plan.feature_locked')], 403);
        }

        $shift = Shift::where('user_id', $request->user()->id)
            ->where('status', Shift::STATUS_OPEN)
            ->latest('opened_at')
            ->first();

        // No open shift: refused while shifts are required. With Settings > POS > "Require open
        // shift to sell" off the sale goes through shift-less (SaleService says the same again).
        // A cashier who has opened a shift anyway keeps it: the sale lands in their drawer count.
        if (!$shift && Setting::get('require_shift', true)) {
            return response()->json(['error' => __('messages.no_open_shift')], 409);
        }

        $payment = array_merge([
            'amount_usd' => 0, 'amount_lbp' => 0, 'amount_card' => 0, 'amount_wish' => 0,
            'amount_credit' => 0, 'loyalty_points_redeemed' => 0,
        ], $data['payment']);

        try {
            $sale = $this->sales->process(
                cart: $data['cart'],
                payment: $payment,
                user: $request->user(),
                shiftId: $shift?->id,
                customerId: $data['customer_id'] ?? null,
                notes: $data['notes'] ?? null,
                priceTier: $data['price_tier'] ?? null,
                cartDiscount: [
                    'pct' => (float) ($data['cart_discount_pct'] ?? 0),
                    'amount' => (float) ($data['cart_discount_amount'] ?? 0),
                ],
                channel: $channel,
                channelSource: $channelSource,
            );

            return response()->json([
                'success' => true,
                'sale' => $sale->only([
                    'id', 'receipt_number', 'price_tier', 'channel', 'channel_source', 'subtotal_usd', 'discount_amount_usd', 'cart_discount_usd',
                    'tax_amount_usd', 'total_usd', 'total_lbp',
                    'change_usd', 'change_lbp', 'payment_method',
                    'loyalty_points_earned',
                ]),
                'items' => $sale->items,
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['error' => $e->errors()], 422);
        } catch (\App\Exceptions\InsufficientStockException $e) {
            return response()->json(['error' => ['stock' => [$e->getMessage()]]], 422);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function show(Request $request, Sale $sale): JsonResponse
    {
        abort_unless($sale->canBeSeenBy($request->user()), 403);

        return response()->json($sale->load('items', 'customer', 'user:id,name'));
    }
}
