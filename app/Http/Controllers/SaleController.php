<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use App\Models\Shift;
use App\Services\SaleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SaleController extends Controller
{
    public function __construct(private readonly SaleService $sales) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'cart' => 'required|array|min:1',
            'cart.*.product_id' => 'required|integer|exists:products,id',
            'cart.*.qty' => 'required|numeric|min:0.0001',
            'cart.*.unit_price' => 'nullable|numeric|min:0',
            'cart.*.discount_pct' => 'nullable|numeric|min:0|max:100',
            'cart.*.discount_amount' => 'nullable|numeric|min:0',
            'cart.*.note' => 'nullable|string|max:255',

            'payment' => 'required|array',
            'payment.method' => 'required|string',
            'payment.amount_usd' => 'nullable|numeric|min:0',
            'payment.amount_lbp' => 'nullable|numeric|min:0',
            'payment.amount_card' => 'nullable|numeric|min:0',
            'payment.amount_credit' => 'nullable|numeric|min:0',
            'payment.card_type' => 'nullable|string|max:50',
            'payment.card_reference' => 'nullable|string|max:100',
            'payment.loyalty_points_redeemed' => 'nullable|integer|min:0',
            'payment.change_usd_out' => 'nullable|numeric|min:0',

            'customer_id' => 'nullable|integer|exists:customers,id',
            'notes' => 'nullable|string|max:1000',
        ]);

        $shift = Shift::where('user_id', $request->user()->id)
            ->where('status', Shift::STATUS_OPEN)
            ->latest('opened_at')
            ->first();

        if (!$shift) {
            return response()->json(['error' => 'No open shift. Open a shift before selling.'], 409);
        }

        $payment = array_merge([
            'amount_usd' => 0, 'amount_lbp' => 0, 'amount_card' => 0,
            'amount_credit' => 0, 'loyalty_points_redeemed' => 0,
        ], $data['payment']);

        try {
            $sale = $this->sales->process(
                cart: $data['cart'],
                payment: $payment,
                userId: $request->user()->id,
                shiftId: $shift->id,
                customerId: $data['customer_id'] ?? null,
                notes: $data['notes'] ?? null,
            );

            return response()->json([
                'success' => true,
                'sale' => $sale->only([
                    'id', 'receipt_number', 'subtotal_usd', 'discount_amount_usd',
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

    public function show(Sale $sale): JsonResponse
    {
        return response()->json($sale->load('items', 'customer', 'user:id,name'));
    }
}
