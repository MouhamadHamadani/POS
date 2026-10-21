<?php

namespace App\Http\Controllers;

use App\Http\Middleware\DemoGate;
use App\Services\ReportService;
use App\Support\Demo;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;
use App\Support\Spreadsheet;

class ReportController extends Controller
{
    public function __construct(private readonly ReportService $reports) {}

    public function index(): View
    {
        return view('reports.index');
    }

    public function dailySales(Request $request): View|Response
    {
        [$from, $to] = $this->range($request);
        $data = $this->reports->dailySales($from, $to);
        $breakdown = $this->reports->dailySalesBreakdown($from, $to);
        $payload = array_merge($data, [
            'from' => $from, 'to' => $to,
            'byPayment' => $breakdown['payments'],
            // The channel split exists only with the sales_channels feature.
            'byChannel' => feature('sales_channels') ? $breakdown['channels'] : null,
        ]);

        return $this->respond($request, 'reports.daily-sales', $payload, 'daily_sales', function () use ($data) {
            $rows = [];
            $rows[] = $this->headings('day', 'transactions', 'subtotal', 'discount', 'tax', 'total');
            foreach ($data['rows'] as $r) {
                $rows[] = [$r['day'], $r['txn_count'], round($r['subtotal'], 4), round($r['discount'], 4), round($r['tax'], 4), round($r['total'], 4)];
            }
            $rows[] = [__('reports.cols.totals'), $data['totals']['txn_count'], round($data['totals']['subtotal'], 4), round($data['totals']['discount'], 4), round($data['totals']['tax'], 4), round($data['totals']['total'], 4)];
            return $rows;
        });
    }

    public function salesByProduct(Request $request): View|Response
    {
        [$from, $to] = $this->range($request);
        $data = $this->reports->salesByProduct($from, $to);
        $payload = array_merge($data, ['from' => $from, 'to' => $to]);

        return $this->respond($request, 'reports.sales-by-product', $payload, 'sales_by_product', function () use ($data) {
            $rows = [$this->headings('product', 'units', 'revenue_ex_vat', 'cogs', 'profit', 'margin_pct')];
            foreach ($data['rows'] as $r) {
                $rows[] = [$r['local_name'], (float) $r['units'], (float) $r['revenue'], (float) $r['cogs'], $r['profit'], $r['margin_pct']];
            }
            return $rows;
        });
    }

    public function stockLevels(Request $request): View|Response
    {
        $statusFilter = $request->query('status_filter');
        $data = $this->reports->stockLevels($statusFilter);
        $payload = array_merge($data, ['status_filter' => $statusFilter]);

        return $this->respond($request, 'reports.stock-levels', $payload, 'stock_levels', function () use ($data) {
            $rows = [$this->headings('product', 'sku', 'stock', 'min', 'unit', 'cost', 'retail', 'cost_value', 'retail_value', 'status')];
            foreach ($data['rows'] as $r) {
                $rows[] = [$r['local_name'], $r['sku'], (float) $r['stock_qty'], (float) $r['min_stock'], $r['unit'], (float) $r['cost_usd'], (float) $r['price_usd'], $r['stock_value_cost'], $r['stock_value_retail'], $r['status']];
            }
            $rows[] = [__('reports.cols.totals'), '', '', '', '', '', '', $data['totals']['value_cost'], $data['totals']['value_retail'], ''];
            return $rows;
        });
    }

    /** Two modes on one screen: stock on a date, or movement between two dates. */
    public function inventoryByDate(Request $request): View|Response
    {
        $in = $request->validate([
            'mode' => 'nullable|in:on_date,movement',
            'date' => 'nullable|date',
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'category_id' => 'nullable|integer',
            'only_moved' => 'nullable|boolean',
        ]);

        $mode = $in['mode'] ?? 'on_date';
        $categoryId = ! empty($in['category_id']) ? (int) $in['category_id'] : null;
        $date = ! empty($in['date']) ? Carbon::parse($in['date'])->startOfDay() : now()->startOfDay();
        $from = ! empty($in['from']) ? Carbon::parse($in['from'])->startOfDay() : now()->startOfMonth();
        $to = ! empty($in['to']) ? Carbon::parse($in['to'])->endOfDay() : now()->endOfDay();
        $onlyMoved = $mode === 'movement' && $request->boolean('only_moved');

        $data = $mode === 'movement'
            ? $this->reports->inventoryMovement($from, $to, $categoryId, $onlyMoved)
            : $this->reports->stockOnDate($date, $categoryId);

        $payload = $data + [
            'mode' => $mode, 'date' => $date, 'from' => $from, 'to' => $to,
            'category_id' => $categoryId, 'only_moved' => $onlyMoved,
            'categories' => \App\Models\Category::orderBy('sort_order')->get(['id', 'name', 'name_ar']),
        ];

        return $this->respond($request, 'reports.inventory-by-date', $payload, 'inventory_by_date', function () use ($data, $mode) {
            if ($mode === 'movement') {
                $rows = [$this->headings('product', 'sku', 'unit', 'opening_qty', 'total_in', 'total_out', 'closing_qty')];
                foreach ($data['rows'] as $r) {
                    $rows[] = [$r['local_name'], $r['sku'], $r['unit'], $r['opening'], $r['qty_in'], $r['qty_out'], $r['closing']];
                }
                $rows[] = [__('reports.cols.totals'), '', '', $data['totals']['opening'], $data['totals']['qty_in'], $data['totals']['qty_out'], $data['totals']['closing']];

                return $rows;
            }

            $rows = [$this->headings('product', 'sku', 'unit', 'qty_on_date', 'cost', 'retail', 'cost_value', 'retail_value')];
            foreach ($data['rows'] as $r) {
                $rows[] = [$r['local_name'], $r['sku'], $r['unit'], $r['qty'], $r['cost_usd'], $r['price_usd'], $r['value_cost'], $r['value_retail']];
            }
            $rows[] = [__('reports.cols.totals'), '', '', '', '', '', $data['totals']['value_cost'], $data['totals']['value_retail']];

            return $rows;
        });
    }

    public function profitLoss(Request $request): View|Response
    {
        [$from, $to] = $this->range($request);
        $data = $this->reports->profitLoss($from, $to);
        $payload = array_merge($data, ['from' => $from, 'to' => $to]);

        return $this->respond($request, 'reports.pnl', $payload, 'pnl', function () use ($data) {
            return [
                [__('reports.pnl.line'), __('reports.pnl.amount_usd')],
                [__('reports.pnl.gross_revenue'), $data['gross_revenue']],
                [__('reports.pnl.discounts'), -$data['discounts']],
                [__('reports.pnl.tax_collected'), $data['tax_collected']],
                [__('reports.pnl.net_revenue'), $data['net_revenue']],
                [__('reports.pnl.ex_vat_revenue'), $data['ex_vat_revenue']],
                [__('reports.pnl.cogs'), -$data['cogs']],
                [__('reports.pnl.gross_profit'), $data['gross_profit']],
                [__('reports.cols.margin_pct'), $data['margin_pct']],
                [__('reports.pnl.fixed_expenses'), -$data['fixed_expenses']],
                [__('reports.pnl.running_expenses'), -$data['running_expenses']],
                [__('reports.pnl.net_profit'), $data['net_profit']],
                [__('reports.pnl.net_margin'), $data['net_margin_pct']],
                [__('reports.cols.transactions'), $data['txn_count']],
            ];
        });
    }

    /** Expenses by category: fixed / running subtotals, share of total, and a month-by-month table. */
    public function expenses(Request $request): View|Response
    {
        $request->validate(['from' => 'nullable|date', 'to' => 'nullable|date|after_or_equal:from']);
        [$from, $to] = $this->range($request, now()->startOfMonth());
        $data = $this->reports->expensesByCategory($from, $to);
        $payload = array_merge($data, ['from' => $from, 'to' => $to]);

        return $this->respond($request, 'reports.expenses', $payload, 'expenses', function () use ($data) {
            $head = [__('reports.cols.category'), __('expenses.cols.kind'), __('reports.cols.amount_usd'), __('reports.cols.share_pct'), ...$data['months']];
            $rows = [$head];
            foreach ($data['categories'] as $c) {
                $rows[] = [
                    $c['local_name'], \App\Support\EnumLabel::get('expense_kind', $c['kind']), $c['total'], $c['pct'],
                    ...array_map(fn ($m) => $data['monthly'][$c['id']][$m] ?? 0, $data['months']),
                ];
            }
            $blank = array_fill(0, count($data['months']), '');
            $rows[] = [\App\Support\EnumLabel::get('expense_kind', 'fixed'), '', $data['fixed'], '', ...$blank];
            $rows[] = [\App\Support\EnumLabel::get('expense_kind', 'running'), '', $data['running'], '', ...$blank];
            $rows[] = [__('reports.cols.totals'), '', $data['total'], '', ...array_values($data['month_totals'])];

            return $rows;
        });
    }

    private function range(Request $request, ?Carbon $default = null): array
    {
        $from = $request->query('from') ? Carbon::parse($request->query('from'))->startOfDay() : ($default ?? now()->subDays(30))->copy()->startOfDay();
        $to = $request->query('to') ? Carbon::parse($request->query('to'))->endOfDay() : now()->endOfDay();
        return [$from, $to];
    }

    /** Export column headings in the current UI language. */
    private function headings(string ...$keys): array
    {
        return array_map(fn ($k) => __("reports.cols.$k"), $keys);
    }

    private function respond(Request $request, string $view, array $payload, string $titleKey, \Closure $exportRows): View|Response
    {
        $format = $request->query('format');
        $title = __("reports.titles.$titleKey");
        // The file name stays ASCII whatever language the UI is in.
        $filename = \Illuminate\Support\Str::slug(__("reports.titles.$titleKey", [], 'en'));

        // TODO(demo-decision): exports are OFF in demo builds pending Mouhamad's
        // call — the alternative is keeping them working but watermarking the
        // filename and PDF header with "DEMO DATA — NOT REAL FIGURES". Reports
        // themselves stay fully browsable on screen either way; this only stops
        // a file of invented figures leaving the meeting looking authoritative.
        if (Demo::enabled() && in_array($format, ['pdf', 'xlsx'], true)) {
            return back()->withErrors(['demo' => DemoGate::refusal().' '.__('messages.reports_viewable')]);
        }

        if ($format === 'pdf') {
            \App\Support\PdfText::ensureFontDirs();
            $pdf = Pdf::loadView($view . '-pdf', $payload + ['title' => $title]);
            return $pdf->download($filename . '-' . now()->format('Ymd-His') . '.pdf');
        }

        if ($format === 'xlsx') {
            // Extension is picked by what this runtime can write — the packaged
            // app has no ext-xmlwriter and cannot produce .xlsx at all.
            return Spreadsheet::downloadRows($exportRows(), $filename . '-' . now()->format('Ymd-His'));
        }

        return view($view, $payload + ['title' => $title]);
    }
}
