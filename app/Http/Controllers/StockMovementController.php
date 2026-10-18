<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\InventoryAdjustment;
use App\Models\Product;
use App\Services\StockMovementService;
use App\Support\Spreadsheet;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class StockMovementController extends Controller
{
    public function __construct(private readonly StockMovementService $movements) {}

    public function index(Request $request): View
    {
        $f = $this->filters($request);

        return view('products.movements', [
            'f' => $f,
            'rows' => $this->movements->paginate($f)->withQueryString(),
            'summary' => $this->movements->summary($f),
            'categories' => Category::orderBy('sort_order')->get(['id', 'name', 'name_ar']),
            // Set when arriving from a product's Movements tab: show its name, not an id.
            'product' => $f['product_id'] ? Product::withTrashed()->find($f['product_id']) : null,
        ]);
    }

    /** `demo:blocked` on the route — a demo build's exports are off like every other. */
    public function export(Request $request): BinaryFileResponse
    {
        return Spreadsheet::downloadRows(
            $this->movements->exportRows($this->filters($request)),
            'stock-movements-'.now()->format('Ymd-His'),
        );
    }

    private function filters(Request $request): array
    {
        return $this->movements->filters($request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'product' => 'nullable|string|max:100',
            'product_id' => 'nullable|integer',
            'category_id' => 'nullable|integer',
            'direction' => 'nullable|in:all,in,out',
            'type' => ['nullable', Rule::in(InventoryAdjustment::TYPES)],
        ]));
    }
}
