<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use App\Models\Setting;
use App\Services\BarcodeService;
use App\Services\ReceiptPrintService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PosController extends Controller
{
    public function sell(Request $request, ReceiptPrintService $prints): View
    {
        $user = $request->user();

        $categories = Category::where('is_active', true)
            ->orderBy('sort_order')
            ->get(['id', 'name', 'name_ar', 'color', 'icon']);

        $columns = [
            'id', 'category_id', 'tax_id', 'barcode', 'sku',
            'name', 'name_ar', 'image',
            'price_usd', 'wholesale_price_usd', 'vip_price_usd',
            'stock_qty', 'unit', 'type',
            'is_taxable', 'allow_discount', 'track_stock',
        ];
        // Cost only goes to the browser of someone who may see margins; it
        // powers the "below cost" warning on the sell screen.
        if ($user->isManagerial()) {
            $columns[] = 'cost_usd';
        }

        // select() first: withSum() would otherwise turn the column list into products.* and ship cost to a cashier.
        $products = Product::select($columns)
            ->with(['tax:id,rate,is_inclusive', 'variants' => fn ($q) => $q->where('is_active', true)->orderBy('id')
                ->select('id', 'product_id', 'barcode', 'label', 'label_ar', 'stock_qty')])
            ->withSum('variants as variants_stock', 'stock_qty') // every variant, active or not: the main item is what they leave
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->each(fn (Product $p) => $this->forTill($p));

        $exchangeRate = (float) Setting::get('exchange_rate', 90000);
        $lbpStep = (float) Setting::get('lbp_rounding_step', 1000);

        return view('pos.sell', [
            'categories' => $categories,
            'products' => $products,
            'exchangeRate' => $exchangeRate,
            'lbpStep' => $lbpStep,
            'autoPrint' => (bool) Setting::get('auto_print', false),
            'receiptFormat' => $prints->formatSetting(),
            'desktop' => $prints->inNativeShell(),
            'canChangeTier' => $user->canChangePriceTier(),
            'showCost' => $user->isManagerial(),
            'maxDiscountPct' => $user->maxDiscountPct(),
            'business' => [
                'name' => Setting::get('business_name', 'LebaSouk'),
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

        return response()->json(
            $q->orderBy('name')->limit(100)->get()->each(fn (Product $p) => $this->hideCostFrom($request, $p))
        );
    }

    /**
     * Live change-split preview + denomination breakdown for the payment modal.
     */
    public function previewChange(Request $request, \App\Services\CurrencyService $currency): JsonResponse
    {
        $data = $request->validate([
            'total_usd' => 'required|numeric|min:0',
            'paid_usd' => 'nullable|numeric|min:0',
            'paid_lbp' => 'nullable|numeric|min:0',
            'change_usd_out' => 'nullable|numeric|min:0',
        ]);

        $change = $currency->calculateChange(
            (float) $data['total_usd'],
            (float) ($data['paid_usd'] ?? 0),
            (float) ($data['paid_lbp'] ?? 0),
            isset($data['change_usd_out']) ? (float) $data['change_usd_out'] : null,
        );

        return response()->json([
            'change' => $change,
            'usd_denoms' => $currency->suggestUsdDenominations($change['change_usd']),
            'lbp_denoms' => $currency->suggestLbpDenominations($change['change_lbp']),
        ]);
    }

    /**
     * A scanned code: a product's own barcode or one of its variants' (BarcodeService is the one namespace).
     * Answers with the product, its active variants and, for a variant code, `variant_id` of the one scanned.
     */
    public function lookupBarcode(Request $request, BarcodeService $barcodes): JsonResponse
    {
        $code = trim((string) $request->query('code'));
        if (!$code) {
            return response()->json(['error' => __('messages.no_barcode')], 422);
        }

        $hit = $barcodes->findByBarcode($code);
        $product = $hit && !$hit['product']->trashed() && $hit['product']->is_active
            ? Product::with(['tax:id,rate,is_inclusive', 'variants' => fn ($q) => $q->where('is_active', true)->orderBy('id')])
                ->withSum('variants as variants_stock', 'stock_qty')->find($hit['product']->id)
            : null;
        if (!$product || ($hit['variant'] && $hit['variant']->trashed())) {
            return response()->json(['error' => __('messages.product_not_found')], 404);
        }
        if ($hit['variant'] && !$hit['variant']->is_active) {
            return response()->json(['error' => __('products.variants.inactive')], 422);
        }

        $this->forTill($product);

        return response()->json($this->hideCostFrom($request, $product)->setAttribute('variant_id', $hit['variant']?->id));
    }

    /**
     * What the till needs of a product beyond its own columns: the main item's own stock (what its variants
     * leave of the total) and each active variant's display name. Variants carry no price: it is the product's.
     */
    private function forTill(Product $p): void
    {
        $p->setAttribute('main_stock', round((float) $p->stock_qty - (float) $p->variants_stock, 4));
        $p->makeHidden('variants_stock');
        $p->variants->each(fn ($v) => $v->setAttribute('display_name', $v->displayName(product: $p))->makeHidden(['price_modifier', 'attributes']));
    }

    /** Cashiers never receive cost data, not even through the lookup endpoints. */
    private function hideCostFrom(Request $request, Product $product): Product
    {
        return $request->user()->isManagerial() ? $product : $product->makeHidden('cost_usd');
    }
}
