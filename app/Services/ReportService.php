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
            ->where('sales.status', Sale::STATUS_COMPLETED)
            ->whereBetween('sales.created_at', [$from, $to])
            ->groupBy('sale_items.product_id', 'sale_items.product_name')
            ->select(
                'sale_items.product_id',
                'sale_items.product_name',
                DB::raw('SUM(sale_items.qty) as units'),
                DB::raw('SUM(sale_items.line_total_usd) as revenue'),
                DB::raw('SUM(sale_items.qty * sale_items.cost_usd) as cogs'),
            )
            ->orderByDesc('revenue')
            ->limit($limit)
            ->get()
            ->map(function ($r) {
                $r->profit = (float) $r->revenue - (float) $r->cogs;
                $r->margin_pct = $r->revenue > 0 ? round($r->profit / $r->revenue * 100, 2) : 0;
                return $r;
            })
            ->toArray();

        return ['rows' => $rows];
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
            'id', 'name', 'sku', 'barcode', 'unit', 'stock_qty', 'min_stock', 'max_stock', 'cost_usd', 'price_usd',
        ])->map(function ($p) {
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
        $grossProfit = ($grossRevenue - $discounts) - $cogs;
        $marginPct = ($grossRevenue - $discounts) > 0 ? round($grossProfit / ($grossRevenue - $discounts) * 100, 2) : 0;

        return [
            'txn_count' => (int) $agg->txn_count,
            'gross_revenue' => $grossRevenue,
            'discounts' => $discounts,
            'tax_collected' => $tax,
            'net_revenue' => $net,
            'cogs' => $cogs,
            'gross_profit' => $grossProfit,
            'margin_pct' => $marginPct,
        ];
    }
}
