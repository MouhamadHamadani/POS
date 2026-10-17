<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ReportService
{
    /**
     * Daily sales summary with totals per day in the given range.
     * @return array{rows: array, totals: array}
     */
    public function dailySales(Carbon $from, Carbon $to): array
    {
        $rows = Sale::query()
            ->select(
                DB::raw('DATE(created_at) as day'),
                DB::raw('COUNT(*) as txn_count'),
                DB::raw('SUM(subtotal_usd) as subtotal'),
                DB::raw('SUM(discount_amount_usd) as discount'),
                DB::raw('SUM(tax_amount_usd) as tax'),
                DB::raw('SUM(total_usd) as total'),
            )
            ->where('status', Sale::STATUS_COMPLETED)
            ->whereBetween('created_at', [$from, $to])
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->toArray();

        $totals = [
            'txn_count' => array_sum(array_column($rows, 'txn_count')),
            'subtotal' => array_sum(array_column($rows, 'subtotal')),
            'discount' => array_sum(array_column($rows, 'discount')),
            'tax' => array_sum(array_column($rows, 'tax')),
            'total' => array_sum(array_column($rows, 'total')),
        ];

        return compact('rows', 'totals');
    }

    /**
     * Sales by product (top-sellers + low-sellers).
     */
    public function salesByProduct(Carbon $from, Carbon $to, int $limit = 100): array
    {
        $rows = SaleItem::query()
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->leftJoin('products', 'products.id', '=', 'sale_items.product_id')
            ->where('sales.status', Sale::STATUS_COMPLETED)
            ->whereBetween('sales.created_at', [$from, $to])
            ->groupBy('sale_items.product_id', 'sale_items.product_name', 'products.name_ar')
            ->select(
                'sale_items.product_id',
                'sale_items.product_name',
                'products.name_ar as product_name_ar',
                DB::raw('SUM(sale_items.qty) as units'),
                // Revenue net of VAT: line_total carries the tax for an
                // exclusive rate and contains it for an inclusive one, so
                // subtracting the line's tax is right either way. VAT is
                // collected for the state, never margin.
                DB::raw('SUM(sale_items.line_total_usd - sale_items.tax_amount_usd) as revenue'),
                DB::raw('SUM(sale_items.qty * sale_items.cost_usd) as cogs'),
            )
            ->orderByDesc('revenue')
            ->limit($limit)
            ->get()
            ->map(function ($r) {
                $r->local_name = app()->getLocale() === 'ar' && $r->product_name_ar ? $r->product_name_ar : $r->product_name;
                $r->profit = (float) $r->revenue - (float) $r->cogs;
                $r->margin_pct = $r->revenue > 0 ? round($r->profit / $r->revenue * 100, 2) : 0;
                return $r;
            })
            ->toArray();

        return ['rows' => $rows];
    }

    /**
     * Lines a cashier sold off the price list: who, who approved it, why, and by how much.
     * `difference` is (charged - list price) x qty, so a cut is negative and a raise positive;
     * voided sales and unfinished holds are left out, a later refund does not remove the line.
     *
     * @return array{rows: array, totals: array{count: int, net_difference: float}}
     */
    public function priceOverrides(Carbon $from, Carbon $to): array
    {
        $rows = SaleItem::query()
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->leftJoin('products', 'products.id', '=', 'sale_items.product_id')
            ->leftJoin('users as setter', 'setter.id', '=', 'sale_items.price_overridden_by')
            ->leftJoin('users as approver', 'approver.id', '=', 'sale_items.price_override_approved_by')
            ->whereNotNull('sale_items.price_overridden_by')
            ->whereNotIn('sales.status', [Sale::STATUS_VOIDED, Sale::STATUS_ON_HOLD])
            ->whereBetween('sales.created_at', [$from, $to])
            ->orderByDesc('sales.created_at')
            ->orderByDesc('sale_items.id')
            ->get([
                'sales.created_at as sold_at', 'sales.receipt_number',
                'sale_items.product_name', 'products.name_ar as product_name_ar',
                'sale_items.qty', 'sale_items.original_unit_price_usd as list_price',
                'sale_items.unit_price_usd as charged_price', 'sale_items.price_override_reason as reason',
                'setter.name as cashier', 'approver.name as approver',
            ])
            ->map(fn ($r) => [
                'date' => Carbon::parse($r->sold_at)->format('Y-m-d H:i'),
                'receipt' => $r->receipt_number,
                'local_name' => app()->getLocale() === 'ar' && $r->product_name_ar ? $r->product_name_ar : $r->product_name,
                'qty' => (float) $r->qty,
                'list_price' => (float) $r->list_price,
                'charged_price' => (float) $r->charged_price,
                'difference' => round(((float) $r->charged_price - (float) $r->list_price) * (float) $r->qty, 4),
                'reason' => $r->reason,
                'cashier' => $r->cashier,
                'approver' => $r->approver,
            ])
            ->all();

        return [
            'rows' => $rows,
            'totals' => [
                'count' => count($rows),
                'net_difference' => round(array_sum(array_column($rows, 'difference')), 4),
            ],
        ];
    }

    /**
     * Current stock levels.
     */
    public function stockLevels(?string $statusFilter = null): array
    {
        $q = Product::where('is_active', true)->where('track_stock', true);
        if ($statusFilter === 'low') {
            $q->whereColumn('stock_qty', '<=', 'min_stock');
        } elseif ($statusFilter === 'out') {
            $q->where('stock_qty', '<=', 0);
        }
        $rows = $q->orderBy('name')->get([
            'id', 'name', 'name_ar', 'sku', 'barcode', 'unit', 'stock_qty', 'min_stock', 'max_stock', 'cost_usd', 'price_usd',
        ])->map(function ($p) {
            $p->local_name = $p->localName();
            $p->stock_value_cost = round((float) $p->stock_qty * (float) $p->cost_usd, 2);
            $p->stock_value_retail = round((float) $p->stock_qty * (float) $p->price_usd, 2);
            $p->status = $p->stock_qty <= 0 ? 'out' : ($p->stock_qty <= $p->min_stock ? 'low' : 'ok');
            return $p;
        });

        $totalCost = $rows->sum('stock_value_cost');
        $totalRetail = $rows->sum('stock_value_retail');

        return [
            'rows' => $rows->toArray(),
            'totals' => [
                'count' => $rows->count(),
                'value_cost' => $totalCost,
                'value_retail' => $totalRetail,
                'margin' => $totalRetail - $totalCost,
            ],
        ];
    }

    /**
     * Stock quantity, cost value and retail value of every tracked product at the
     * end of a past day.
     *
     *   stock_at(D) = current stock_qty - sum(qty_change of adjustments after D)
     *
     * One grouped query over inventory_adjustments, joined to products — never a
     * query per product. A product created after D is left out; so is one deleted
     * before the next day starts. Value at cost uses today's cost_usd.
     *
     * @return array{rows: array, totals: array}
     */
    public function stockOnDate(Carbon $date, ?int $categoryId = null): array
    {
        $cut = $date->copy()->addDay()->startOfDay();   // first instant after D

        $after = DB::table('inventory_adjustments')
            ->selectRaw('product_id, SUM(qty_change) AS delta_after')
            ->where('created_at', '>=', $cut)
            ->groupBy('product_id');

        $rows = $this->historyProducts($cut, $cut, $categoryId)
            ->leftJoinSub($after, 'a', 'a.product_id', '=', 'products.id')
            ->selectRaw('COALESCE(a.delta_after, 0) AS delta_after')
            ->get()
            ->map(function ($p) {
                $qty = round((float) $p->stock_qty - (float) $p->delta_after, 4);

                return $this->historyRow($p) + [
                    'qty' => $qty,
                    'value_cost' => round($qty * (float) $p->cost_usd, 2),
                    'value_retail' => round($qty * (float) $p->price_usd, 2),
                ];
            })->all();

        $cost = array_sum(array_column($rows, 'value_cost'));
        $retail = array_sum(array_column($rows, 'value_retail'));

        return [
            'rows' => $rows,
            'totals' => ['count' => count($rows), 'value_cost' => $cost, 'value_retail' => $retail, 'margin' => $retail - $cost],
        ];
    }

    /**
     * Opening quantity, total in, total out and closing quantity per product for a
     * date range. Opening is stock_at(from - 1 day) and closing is stock_at(to), so
     * closing = opening + in - out holds by construction; see stockOnDate().
     *
     * @return array{rows: array, totals: array}
     */
    public function inventoryMovement(Carbon $from, Carbon $to, ?int $categoryId = null, bool $onlyMoved = false): array
    {
        $start = $from->copy()->startOfDay();
        $cut = $to->copy()->addDay()->startOfDay();     // first instant after the range

        // Everything is derived from the rows at or after `from`, so that is all
        // that gets scanned.
        $moves = DB::table('inventory_adjustments')
            ->selectRaw('product_id,
                SUM(qty_change) AS since_from,
                SUM(CASE WHEN created_at >= ? THEN qty_change ELSE 0 END) AS since_to,
                SUM(CASE WHEN created_at < ? AND qty_change > 0 THEN qty_change ELSE 0 END) AS qty_in,
                SUM(CASE WHEN created_at < ? AND qty_change < 0 THEN -qty_change ELSE 0 END) AS qty_out', [$cut, $cut, $cut])
            ->where('created_at', '>=', $start)
            ->groupBy('product_id');

        $query = $this->historyProducts($cut, $start, $categoryId);
        if ($onlyMoved) {
            $query->joinSub($moves, 'a', 'a.product_id', '=', 'products.id')
                ->where(fn ($w) => $w->where('a.qty_in', '>', 0)->orWhere('a.qty_out', '>', 0));
        } else {
            $query->leftJoinSub($moves, 'a', 'a.product_id', '=', 'products.id');
        }

        $rows = $query
            ->selectRaw('COALESCE(a.since_from, 0) AS since_from, COALESCE(a.since_to, 0) AS since_to,
                         COALESCE(a.qty_in, 0) AS qty_in, COALESCE(a.qty_out, 0) AS qty_out')
            ->get()
            ->map(fn ($p) => $this->historyRow($p) + [
                'opening' => round((float) $p->stock_qty - (float) $p->since_from, 4),
                'qty_in' => round((float) $p->qty_in, 4),
                'qty_out' => round((float) $p->qty_out, 4),
                'closing' => round((float) $p->stock_qty - (float) $p->since_to, 4),
            ])->all();

        return [
            'rows' => $rows,
            'totals' => [
                'count' => count($rows),
                'opening' => round(array_sum(array_column($rows, 'opening')), 4),
                'qty_in' => round(array_sum(array_column($rows, 'qty_in')), 4),
                'qty_out' => round(array_sum(array_column($rows, 'qty_out')), 4),
                'closing' => round(array_sum(array_column($rows, 'closing')), 4),
            ],
        ];
    }

    /**
     * Products that existed at some point in the window [$existsFrom, $createdBefore):
     * tracked, created before the window ends, not deleted before it starts.
     */
    private function historyProducts(Carbon $createdBefore, Carbon $existsFrom, ?int $categoryId): \Illuminate\Database\Query\Builder
    {
        return DB::table('products')
            ->where('products.track_stock', true)
            ->where(fn ($q) => $q->whereNull('products.created_at')->orWhere('products.created_at', '<', $createdBefore))
            ->where(fn ($q) => $q->whereNull('products.deleted_at')->orWhere('products.deleted_at', '>=', $existsFrom))
            ->when($categoryId, fn ($q) => $q->where('products.category_id', $categoryId))
            ->orderBy('products.name')
            ->select('products.id', 'products.name', 'products.name_ar', 'products.sku', 'products.unit',
                'products.stock_qty', 'products.cost_usd', 'products.price_usd');
    }

    private function historyRow(object $p): array
    {
        return [
            'id' => $p->id,
            'name' => $p->name,
            'local_name' => app()->getLocale() === 'ar' && $p->name_ar ? $p->name_ar : $p->name,
            'sku' => $p->sku,
            'unit' => $p->unit,
            'cost_usd' => (float) $p->cost_usd,
            'price_usd' => (float) $p->price_usd,
        ];
    }

    /**
     * Simple P&L: revenue - cogs = gross profit; discounts and tax broken out separately.
     */
    public function profitLoss(Carbon $from, Carbon $to): array
    {
        $agg = Sale::query()
            ->where('status', Sale::STATUS_COMPLETED)
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('SUM(subtotal_usd) as gross_revenue,
                         SUM(discount_amount_usd) as discounts,
                         SUM(tax_amount_usd) as tax_collected,
                         SUM(total_usd) as net_revenue,
                         COUNT(*) as txn_count')
            ->first();

        $cogs = (float) SaleItem::query()
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.status', Sale::STATUS_COMPLETED)
            ->whereBetween('sales.created_at', [$from, $to])
            ->sum(DB::raw('sale_items.qty * sale_items.cost_usd'));

        $grossRevenue = (float) $agg->gross_revenue;
        $discounts = (float) $agg->discounts;
        $tax = (float) $agg->tax_collected;
        $net = (float) $agg->net_revenue;
        // Revenue actually earned: what was charged, less the VAT inside it.
        // (net_revenue - tax) equals (subtotal - discounts) for an exclusive
        // rate and strips the embedded VAT for an inclusive one.
        $exVatRevenue = $net - $tax;
        $grossProfit = $exVatRevenue - $cogs;
        $marginPct = $exVatRevenue > 0 ? round($grossProfit / $exVatRevenue * 100, 2) : 0;

        // Expenses belong to the period of their own date, not of the day they were keyed in.
        $spent = $this->expenseTotalsByKind($from, $to);
        $totalExpenses = $spent['fixed'] + $spent['running'];
        $netProfit = $grossProfit - $totalExpenses;

        return [
            'txn_count' => (int) $agg->txn_count,
            'gross_revenue' => $grossRevenue,
            'discounts' => $discounts,
            'tax_collected' => $tax,
            'net_revenue' => $net,
            'ex_vat_revenue' => round($exVatRevenue, 4),
            'cogs' => $cogs,
            'gross_profit' => $grossProfit,
            'margin_pct' => $marginPct,
            'fixed_expenses' => $spent['fixed'],
            'running_expenses' => $spent['running'],
            'total_expenses' => $totalExpenses,
            'net_profit' => $netProfit,
            'net_margin_pct' => $exVatRevenue > 0 ? round($netProfit / $exVatRevenue * 100, 2) : 0,
        ];
    }

    /** @return array{fixed: float, running: float} USD, by the date the expense belongs to. */
    private function expenseTotalsByKind(Carbon $from, Carbon $to): array
    {
        $usd = DB::table('expenses as e')
            ->join('expense_categories as c', 'c.id', '=', 'e.expense_category_id')
            ->whereNull('e.deleted_at')
            ->whereBetween('e.date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('c.kind')
            ->selectRaw('c.kind AS kind, SUM(e.amount_usd) AS usd')
            ->pluck('usd', 'kind');

        return ['fixed' => (float) ($usd['fixed'] ?? 0), 'running' => (float) ($usd['running'] ?? 0)];
    }

    /**
     * Expenses per category for a date range, split fixed / running, with each
     * category's share of the total. For a range that spans more than one calendar
     * month it also returns a month-by-month matrix (category x month).
     *
     * @return array{categories: array, fixed: float, running: float, total: float, total_lbp: float, months: array, monthly: array, month_totals: array}
     */
    public function expensesByCategory(Carbon $from, Carbon $to): array
    {
        $grouped = DB::table('expenses as e')
            ->join('expense_categories as c', 'c.id', '=', 'e.expense_category_id')
            ->whereNull('e.deleted_at')
            ->whereBetween('e.date', [$from->toDateString(), $to->toDateString()])
            ->groupByRaw('c.id, c.name, c.name_ar, c.kind, c.sort_order, SUBSTR(e.date, 1, 7)')
            ->selectRaw('c.id, c.name, c.name_ar, c.kind, c.sort_order, SUBSTR(e.date, 1, 7) AS month,
                         SUM(e.amount_usd) AS usd, SUM(e.amount_lbp) AS lbp')
            ->get();

        $monthly = [];
        $categories = [];
        foreach ($grouped as $r) {
            $categories[$r->id] ??= [
                'id' => $r->id,
                'local_name' => app()->getLocale() === 'ar' && $r->name_ar ? $r->name_ar : $r->name,
                'kind' => $r->kind,
                'sort_order' => $r->sort_order,
                'total' => 0.0,
                'total_lbp' => 0.0,
            ];
            $categories[$r->id]['total'] += (float) $r->usd;
            $categories[$r->id]['total_lbp'] += (float) $r->lbp;
            $monthly[$r->id][$r->month] = round((float) $r->usd, 4);
        }

        $total = round(array_sum(array_column($categories, 'total')), 4);
        $categories = collect($categories)
            ->map(fn ($c) => $c + ['pct' => $total > 0 ? round($c['total'] / $total * 100, 1) : 0.0])
            ->sortBy([['kind', 'asc'], ['sort_order', 'asc']])   // 'fixed' sorts before 'running'
            ->values()->all();

        $kindTotal = fn (string $kind) => round(array_sum(array_column(array_filter($categories, fn ($c) => $c['kind'] === $kind), 'total')), 4);

        // Every calendar month the range touches, including empty ones, but only
        // when there is more than one — a single month is just the totals above.
        $months = [];
        if ($from->format('Y-m') !== $to->format('Y-m')) {
            foreach (\Carbon\CarbonPeriod::create($from->copy()->startOfMonth(), '1 month', $to->copy()->startOfMonth()) as $m) {
                $months[] = $m->format('Y-m');
            }
        }
        $monthTotals = [];
        foreach ($months as $m) {
            $monthTotals[$m] = round(array_sum(array_map(fn ($byMonth) => $byMonth[$m] ?? 0, $monthly)), 4);
        }

        return [
            'categories' => $categories,
            'fixed' => $kindTotal('fixed'),
            'running' => $kindTotal('running'),
            'total' => $total,
            'total_lbp' => round(array_sum(array_column($categories, 'total_lbp')), 2),
            'months' => $months,
            'monthly' => $monthly,
            'month_totals' => $monthTotals,
        ];
    }
}
