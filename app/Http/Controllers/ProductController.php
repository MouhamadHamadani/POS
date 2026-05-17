<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProductRequest;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Product;
use App\Models\Tax;
use App\Services\BarcodeService;
use App\Services\InventoryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class ProductController extends Controller
{
    public function __construct(
        private readonly BarcodeService $barcodes,
        private readonly InventoryService $inventory,
    ) {}

    public function index(Request $request): View
    {
        $q = Product::with('category:id,name', 'tax:id,name,rate');

        if ($s = $request->query('search')) {
            $q->where(function ($w) use ($s) {
                $w->where('name', 'like', "%{$s}%")
                  ->orWhere('name_ar', 'like', "%{$s}%")
                  ->orWhere('sku', 'like', "%{$s}%")
                  ->orWhere('barcode', 'like', "%{$s}%");
            });
        }

        if ($cat = $request->query('category')) {
            $q->where('category_id', (int) $cat);
        }

        if ($request->query('status') === 'inactive') {
            $q->where('is_active', false);
        } elseif ($request->query('status') === 'active' || !$request->has('status')) {
            $q->where('is_active', true);
        }

        if ($request->query('low_stock') === '1') {
            $q->whereColumn('stock_qty', '<=', 'min_stock')->where('track_stock', true);
        }

        $products = $q->orderBy('name')->paginate(25)->withQueryString();
        $categories = Category::orderBy('sort_order')->get(['id', 'name']);

        return view('products.index', compact('products', 'categories'));
    }

    public function create(): View
    {
        return view('products.form', [
            'product' => new Product([
                'is_active' => true,
                'is_taxable' => true,
                'allow_discount' => true,
                'track_stock' => true,
                'type' => Product::TYPE_SIMPLE,
                'unit' => 'pcs',
            ]),
            'categories' => Category::orderBy('sort_order')->get(),
            'taxes' => Tax::where('is_active', true)->get(),
            'defaultTaxId' => Tax::where('is_default', true)->value('id'),
        ]);
    }

    public function edit(Product $product): View
    {
        return view('products.form', [
            'product' => $product,
            'categories' => Category::orderBy('sort_order')->get(),
            'taxes' => Tax::where('is_active', true)->get(),
            'defaultTaxId' => Tax::where('is_default', true)->value('id'),
        ]);
    }

    public function store(StoreProductRequest $request): RedirectResponse
    {
        $data = $request->validated();

        if (empty($data['barcode'])) {
            $data['barcode'] = $this->barcodes->generateUniqueEan13();
        }

        if ($request->hasFile('image')) {
            $data['image'] = $request->file('image')->store('products', 'public');
        }
        unset($data['remove_image']);

        $data['created_by'] = $request->user()->id;

        $product = Product::create($data);

        AuditLog::record($request->user()->id, 'create', Product::class, $product->id, null, $data);

        return redirect()->route('products.index')->with('success', "Created '{$product->name}'");
    }

    public function update(StoreProductRequest $request, Product $product): RedirectResponse
    {
        $data = $request->validated();

        if ($request->boolean('remove_image') && $product->image) {
            Storage::disk('public')->delete($product->image);
            $data['image'] = null;
        } elseif ($request->hasFile('image')) {
            if ($product->image) {
                Storage::disk('public')->delete($product->image);
            }
            $data['image'] = $request->file('image')->store('products', 'public');
        } else {
            unset($data['image']);
        }
        unset($data['remove_image']);

        $old = $product->only(array_keys($data));
        $product->update($data);

        AuditLog::record($request->user()->id, 'update', Product::class, $product->id, $old, $data);

        return redirect()->route('products.index')->with('success', "Updated '{$product->name}'");
    }

    public function destroy(Request $request, Product $product): RedirectResponse
    {
        $name = $product->name;
        $product->delete(); // soft delete via SoftDeletes trait

        AuditLog::record($request->user()->id, 'delete', Product::class, $product->id, ['name' => $name], null);

        return redirect()->route('products.index')->with('success', "Deleted '{$name}'");
    }

    public function adjustStock(Request $request, Product $product): RedirectResponse
    {
        $data = $request->validate([
            'action' => 'required|in:add,remove,set,count',
            'qty' => 'required|numeric|min:0',
            'reason' => 'required|string|max:255',
        ]);

        $qty = (float) $data['qty'];

        try {
            match ($data['action']) {
                'add' => $this->inventory->adjust($product, $qty, \App\Models\InventoryAdjustment::TYPE_ADD, $request->user()->id, reason: $data['reason']),
                'remove' => $this->inventory->adjust($product, -$qty, \App\Models\InventoryAdjustment::TYPE_REMOVE, $request->user()->id, reason: $data['reason'], allowNegative: false),
                'set', 'count' => $this->inventory->setStock($product, $qty, $request->user()->id, $data['reason']),
            };
        } catch (\App\Exceptions\InsufficientStockException $e) {
            return back()->withErrors(['stock' => $e->getMessage()]);
        }

        return redirect()->route('products.edit', $product)->with('success', 'Stock adjusted.');
    }
}
