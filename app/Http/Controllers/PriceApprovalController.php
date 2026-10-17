<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\PriceOverrideService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** A manager vouching, at the till, for a price a cashier may not set alone. */
class PriceApprovalController extends Controller
{
    public function __construct(private readonly PriceOverrideService $overrides) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'price' => 'required|numeric|gt:0',
            'login' => 'required|string|max:255',
            'secret' => 'required|string|max:255',
        ]);

        abort_unless($this->overrides->enabled(), 403, __('pos.errors.price_override_off'));

        return response()->json($this->overrides->grant(
            $request->user(),
            Product::findOrFail($data['product_id']),
            (float) $data['price'],
            $data['login'],
            $data['secret'],
        ));
    }
}
