<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\HeldSale;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class HeldSaleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = HeldSale::with('customer:id,name')->latest();

        // Cashiers see only their own holds; admin/manager see everyone's.
        if (! $user->isManagerial()) {
            $query->where('user_id', $user->id);
        }

        $holds = $query->limit(50)->get();

        // A hold stores no prices (the server prices at checkout), so the list's
        // subtotal is worked out from today's prices on each hold's price list.
        $products = Product::whereIn('id', $holds->flatMap(fn (HeldSale $h) => array_column((array) $h->cart, 'product_id')))
            ->get(['id', 'price_usd', 'wholesale_price_usd', 'vip_price_usd'])
            ->keyBy('id');

        return response()->json(
            $holds->map(fn (HeldSale $h) => [
                'id' => $h->id,
                'label' => $h->label,
                'customer' => $h->customer?->only(['id', 'name']),
                'item_count' => is_array($h->cart) ? count($h->cart) : 0,
                'subtotal' => $this->subtotal($h, $products),
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
            // No cart.*.unit_price: a hold keeps no prices, the sell screen
            // re-prices from current product data on recall.
            'cart.*.discount_pct' => 'nullable|numeric|min:0|max:100',
            'cart.*.discount_amount' => 'nullable|numeric|min:0',
            'cart.*.note' => 'nullable|string|max:255',
            'price_tier' => ['nullable', Rule::in(Customer::GROUPS)],
            'cart_discount' => 'nullable|array',
            'cart_discount.type' => 'required_with:cart_discount|in:pct,amount',
            'cart_discount.value' => 'required_with:cart_discount|numeric|min:0',
            'customer_id' => 'nullable|integer|exists:customers,id',
            'label' => 'nullable|string|max:80',
            'notes' => 'nullable|string|max:500',
        ]);

        $held = HeldSale::create([
            'user_id' => $request->user()->id,
            'customer_id' => $data['customer_id'] ?? null,
            'label' => $data['label'] ?? null,
            'cart' => $data['cart'],
            'price_tier' => $data['price_tier'] ?? Customer::GROUP_RETAIL,
            'cart_discount' => $data['cart_discount'] ?? null,
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

        // Return the cart and delete the hold in one shot. `customer` carries
        // the same shape the POS typeahead returns so the sell screen can
        // restore the customer chip without a second round trip.
        $heldSale->loadMissing('customer');

        $payload = [
            'cart' => $heldSale->cart,
            'customer_id' => $heldSale->customer_id,
            'customer' => $heldSale->customer?->posSummary(),
            'price_tier' => $heldSale->price_tier,
            'cart_discount' => $heldSale->cart_discount,
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
        $isManagerial = $user->isManagerial();
        abort_unless($isOwner || $isManagerial, 403, 'You can only access your own held sales.');
    }

    /** @param \Illuminate\Support\Collection<int, Product> $products keyed by id */
    private function subtotal(HeldSale $hold, $products): float
    {
        $total = 0;
        foreach ((array) $hold->cart as $line) {
            $price = $products->get($line['product_id'] ?? 0)?->priceForGroup($hold->price_tier) ?? 0;
            $qty = (float) ($line['qty'] ?? 0);
            $total += $price * $qty;
        }
        return round($total, 2);
    }
}
