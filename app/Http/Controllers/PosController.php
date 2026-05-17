<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PosController extends Controller
{
    public function sell(): View
    {
        $categories = Category::where('is_active', true)
            ->orderBy('sort_order')
            ->get(['id', 'name', 'name_ar', 'color', 'icon']);

        $products = Product::with('tax:id,rate,is_inclusive')
            ->where('is_active', true)
            ->orderBy('name')
            ->get([
                'id', 'category_id', 'tax_id', 'barcode', 'sku',
                'name', 'name_ar', 'image',
                'price_usd', 'wholesale_price_usd', 'vip_price_usd',
                'stock_qty', 'unit', 'type',
                'is_taxable', 'allow_discount', 'track_stock',
            ]);

        $exchangeRate = (float) Setting::get('exchange_rate', 90000);
        $lbpStep = (float) Setting::get('lbp_rounding_step', 1000);

        return view('pos.sell', [
            'categories' => $categories,
            'products' => $products,
            'exchangeRate' => $exchangeRate,
            'lbpStep' => $lbpStep,
            'business' => [
                'name' => Setting::get('business_name', 'POS Pro'),
            ],
        ]);
    }

    public function products(Request $request): JsonResponse
    {
        $q = Product::with('tax:id,rate')
            ->where('is_active', true);

        if ($search = $request->query('search')) {
            $q->where(function ($w) use ($search) {
                $w->where('name', 'like', "%{$search}%")
                    ->orWhere('name_ar', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%")
                    ->orWhere('barcode', $search);
            });
        }

        if ($cat = $request->query('category_id')) {
            $q->where('category_id', (int) $cat);
        }

        return response()->json($q->orderBy('name')->limit(100)->get());
    }

    public function lookupBarcode(Request $request): JsonResponse
    {
        $code = trim((string) $request->query('code'));
        if (!$code) {
            return response()->json(['error' => 'No barcode'], 422);
        }
        $product = Product::with('tax:id,rate,is_inclusive')->where('barcode', $code)->first();
        if (!$product) {
            return response()->json(['error' => 'Product not found'], 404);
        }
        return response()->json($product);
    }
}
