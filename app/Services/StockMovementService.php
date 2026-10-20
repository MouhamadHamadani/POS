<?php

namespace App\Services;

use App\Models\InventoryAdjustment;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\ReturnTransaction;
use App\Models\Sale;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Read side of `inventory_adjustments`: the stock-movements screen, the product
 * "Movements" tab and the CSV export. Every stock change already writes one row
 * (see InventoryService); this only filters and presents them.
 */
class StockMovementService
{
    public const PER_PAGE = 50;

    /**
     * Normalise raw query input into the filter shape every method here takes.
     * The caller has validated the input; this only fills defaults.
     *
     * @return array{from: Carbon, to: Carbon, product: string, product_id: ?int, category_id: ?int, direction: string, type: ?string}
     */
    public function filters(array $input): array
    {
        return [
            'from' => ! empty($input['from']) ? Carbon::parse($input['from'])->startOfDay() : now()->startOfMonth(),
            'to' => ! empty($input['to']) ? Carbon::parse($input['to'])->endOfDay() : now()->endOfMonth()->endOfDay(),
            'product' => trim((string) ($input['product'] ?? '')),
            'product_id' => ! empty($input['product_id']) ? (int) $input['product_id'] : null,
            'category_id' => ! empty($input['category_id']) ? (int) $input['category_id'] : null,
            'direction' => in_array($input['direction'] ?? 'all', ['in', 'out'], true) ? $input['direction'] : 'all',
            'type' => ! empty($input['type']) ? (string) $input['type'] : null,
        ];
    }

    public function query(array $f): Builder
    {
        $q = InventoryAdjustment::query()
            ->whereBetween('inventory_adjustments.created_at', [$f['from'], $f['to']]);

        if ($f['product_id']) {
            $q->where('product_id', $f['product_id']);
        }

        // Name / Arabic name / SKU / barcode and category both narrow the same
        // product set. withTrashed: a deleted product's history stays readable.
        if ($f['product'] !== '' || $f['category_id']) {
            $products = Product::withTrashed()->select('id');
            if ($f['category_id']) {
                $products->where('category_id', $f['category_id']);
            }
            if ($f['product'] !== '') {
                $s = $f['product'];
                $products->where(fn ($w) => $w->where('name', 'like', "%{$s}%")
                    ->orWhere('name_ar', 'like', "%{$s}%")
                    ->orWhere('sku', 'like', "%{$s}%")
                    ->orWhere('barcode', 'like', "%{$s}%"));
            }
            $q->whereIn('product_id', $products);
        }

        if ($f['direction'] === 'in') {
            $q->where('qty_change', '>', 0);
        } elseif ($f['direction'] === 'out') {
            $q->where('qty_change', '<', 0);
        }

        if ($f['type']) {
            $q->where('type', $f['type']);
        }

        return $q;
    }

    /** @return array{in: float, out: float, net: float} */
    public function summary(array $f): array
    {
        $row = $this->query($f)->toBase()->selectRaw(
            'COALESCE(SUM(CASE WHEN qty_change > 0 THEN qty_change ELSE 0 END), 0) AS qty_in,
             COALESCE(SUM(CASE WHEN qty_change < 0 THEN -qty_change ELSE 0 END), 0) AS qty_out'
        )->first();

        $in = round((float) $row->qty_in, 4);
        $out = round((float) $row->qty_out, 4);

        return ['in' => $in, 'out' => $out, 'net' => round($in - $out, 4)];
    }

    public function paginate(array $f): LengthAwarePaginator
    {
        $page = $this->ordered($this->query($f))->paginate(self::PER_PAGE);
        $this->decorate($page->getCollection());

        return $page;
    }

    /** Latest movements of one product, for the product edit page tab. */
    public function recent(Product $product, int $limit = 50): Collection
    {
        $rows = $this->ordered(InventoryAdjustment::query()->where('product_id', $product->id))
            ->limit($limit)->get();

        return $this->decorate($rows);
    }

    /**
     * Export rows (heading first), same filters as the screen.
     * ponytail: loads the whole filtered range at once; chunk it if exports of a
     * multi-year range ever hit the memory limit.
     */
    public function exportRows(array $f): array
    {
        $rows = $this->decorate($this->ordered($this->query($f))->get());

        $out = [[
            __('stock.cols.date'), __('stock.cols.product'), __('stock.cols.sku'), __('stock.cols.type'),
            __('stock.cols.qty_in'), __('stock.cols.qty_out'), __('stock.cols.balance'),
            __('stock.cols.reference'), __('stock.cols.user'), __('stock.cols.notes'),
        ]];

        foreach ($rows as $r) {
            $change = (float) $r->qty_change;
            $out[] = [
                $r->created_at->format('Y-m-d H:i'),
                $r->product?->localName(),
                $r->product?->sku,
                \App\Support\EnumLabel::get('stock_movement_type', $r->type),
                $change > 0 ? $change : '',
                $change < 0 ? -$change : '',
                (float) $r->qty_after,
                $r->ref_label,
                $r->user?->name,
                trim(($r->reason ?? '').' '.($r->notes ?? '')),
            ];
        }

        return $out;
    }

    private function ordered(Builder $q): Builder
    {
        return $q->with([
            'product' => fn ($p) => $p->withTrashed()->select('id', 'name', 'name_ar', 'sku', 'unit'),
            'user:id,name',
        ])->orderByDesc('inventory_adjustments.created_at')->orderByDesc('inventory_adjustments.id');
    }

    /**
     * Give each row `ref_label` and `ref_url` (null = nothing to link to). The
     * source documents are fetched once per kind, not once per row.
     */
    private function decorate(Collection $rows): Collection
    {
        $sales = $this->numbers($rows, 'sale', Sale::class, 'receipt_number');
        $orders = $this->numbers($rows, 'purchase_order', PurchaseOrder::class, 'po_number');
        $returns = $this->numbers($rows, 'return', ReturnTransaction::class, 'return_number');

        // Stock Movements is for admin/manager/stock; of those only the first two have Sales History,
        // and both see every sale, so no per-sale ownership check is needed here.
        $salesHistory = auth()->user()?->canViewSalesHistory() ?? false;

        foreach ($rows as $r) {
            $id = $r->reference_id;
            [$r->ref_label, $r->ref_url] = match ($r->reference_type) {
                'sale' => [$sales[$id] ?? "#{$id}", isset($sales[$id]) && $salesHistory ? route('sales.show', $id) : null],
                'purchase_order' => [$orders[$id] ?? "#{$id}", isset($orders[$id]) && feature('suppliers') ? route('purchases.show', $id) : null],
                'return' => [$returns[$id] ?? "#{$id}", isset($returns[$id]) && feature('returns') ? route('returns.show', $id) : null],
                default => [$r->type === InventoryAdjustment::TYPE_OPENING ? '—' : __('stock.manual'), null],
            };
        }

        return $rows;
    }

    /** @return Collection<int, string> document id => its human number, for the rows that reference one. */
    private function numbers(Collection $rows, string $type, string $model, string $column): Collection
    {
        $ids = $rows->where('reference_type', $type)->pluck('reference_id')->filter()->unique()->values()->all();

        return $ids === [] ? collect() : $model::query()->whereIn('id', $ids)->pluck($column, 'id');
    }
}
