<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\HeldSale;
use App\Models\Product;
use App\Models\Sale;
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
                'customer' => feature('loyalty') ? $h->customer?->only(['id', 'name']) : null,
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
            'channel' => ['nullable', Rule::in(Sale::CHANNELS)],
            'channel_source' => ['nullable', Rule::in(Sale::CHANNEL_SOURCES)],
        ]);
        [$channel, $channelSource] = Sale::resolveChannel($data['channel'] ?? null, $data['channel_source'] ?? null);

        $held = HeldSale::create([
            'user_id' => $request->user()->id,
            // Customers are the loyalty feature's; a hold made without it is a walk-in hold.
            'customer_id' => feature('loyalty') ? ($data['customer_id'] ?? null) : null,
            'label' => $data['label'] ?? null,
            'cart' => $data['cart'],
            'price_tier' => $data['price_tier'] ?? Customer::GROUP_RETAIL,
            'channel' => $channel,
            'channel_source' => $channelSource,
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

        // A hold made while loyalty was on comes back as a walk-in cart once it is off: the
        // till has no customer chip to show or remove, and the sale endpoint would refuse it.
        $withCustomer = feature('loyalty');
        [$channel, $channelSource] = Sale::resolveChannel($heldSale->channel, $heldSale->channel_source);

        $payload = [
            'cart' => $heldSale->cart,
            'customer_id' => $withCustomer ? $heldSale->customer_id : null,
            'customer' => $withCustomer ? $heldSale->customer?->posSummary() : null,
            'price_tier' => $heldSale->price_tier,
            // A hold parked while the feature was on comes back on-site once it is off.
            'channel' => $channel,
            'channel_source' => $channelSource,
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
        abort_unless($isOwner || $isManagerial, 403, __('messages.held_own_only'));
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
