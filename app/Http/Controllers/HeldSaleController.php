<?php

namespace App\Http\Controllers;

use App\Models\HeldSale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HeldSaleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = HeldSale::with('customer:id,name')->latest();

        // Cashiers see only their own holds; admin/manager see everyone's.
        if (! in_array($user->role, ['admin', 'manager'], true)) {
            $query->where('user_id', $user->id);
        }

        return response()->json(
            $query->limit(50)->get()->map(fn (HeldSale $h) => [
                'id' => $h->id,
                'label' => $h->label,
                'customer' => $h->customer?->only(['id', 'name']),
                'item_count' => is_array($h->cart) ? count($h->cart) : 0,
                'subtotal' => $this->subtotal($h),
                'notes' => $h->notes,
                'held_by' => $h->user_id,
                'held_at' => $h->created_at?->toIso8601String(),
            ])
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'cart' => 'required|array|min:1',
            'cart.*.product_id' => 'required|integer|exists:products,id',
            'cart.*.qty' => 'required|numeric|min:0.0001',
            'cart.*.unit_price' => 'required|numeric|min:0',
            'cart.*.discount_pct' => 'nullable|numeric|min:0|max:100',
            'cart.*.discount_amount' => 'nullable|numeric|min:0',
            'cart.*.note' => 'nullable|string|max:255',
            'customer_id' => 'nullable|integer|exists:customers,id',
            'label' => 'nullable|string|max:80',
            'notes' => 'nullable|string|max:500',
        ]);

        $held = HeldSale::create([
            'user_id' => $request->user()->id,
            'customer_id' => $data['customer_id'] ?? null,
            'label' => $data['label'] ?? null,
            'cart' => $data['cart'],
            'notes' => $data['notes'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'id' => $held->id,
            'label' => $held->label,
        ], 201);
    }

    public function recall(Request $request, HeldSale $heldSale): JsonResponse
    {
        $this->authorizeHold($request, $heldSale);

        // Return the cart and delete the hold in one shot.
        $payload = [
            'cart' => $heldSale->cart,
            'customer_id' => $heldSale->customer_id,
            'notes' => $heldSale->notes,
            'label' => $heldSale->label,
        ];
        $heldSale->delete();

        return response()->json(['success' => true] + $payload);
    }

    public function destroy(Request $request, HeldSale $heldSale): JsonResponse
    {
        $this->authorizeHold($request, $heldSale);
        $heldSale->delete();
        return response()->json(['success' => true]);
    }

    private function authorizeHold(Request $request, HeldSale $hold): void
    {
        $user = $request->user();
        $isOwner = $hold->user_id === $user->id;
        $isManagerial = in_array($user->role, ['admin', 'manager'], true);
        abort_unless($isOwner || $isManagerial, 403, 'You can only access your own held sales.');
    }

    private function subtotal(HeldSale $hold): float
    {
        $total = 0;
        foreach ((array) $hold->cart as $line) {
            $price = (float) ($line['unit_price'] ?? 0);
            $qty = (float) ($line['qty'] ?? 0);
            $total += $price * $qty;
        }
        return round($total, 2);
    }
}
