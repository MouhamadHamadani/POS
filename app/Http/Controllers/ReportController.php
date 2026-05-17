<?php

namespace App\Http\Controllers;

use App\Services\ReportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Facades\Excel;

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
        $payload = array_merge($data, ['from' => $from, 'to' => $to]);

        return $this->respond($request, 'reports.daily-sales', $payload, 'Daily Sales Summary', function () use ($data) {
            $rows = [];
            $rows[] = ['Day', 'Transactions', 'Subtotal', 'Discount', 'Tax', 'Total'];
            foreach ($data['rows'] as $r) {
                $rows[] = [$r['day'], $r['txn_count'], round($r['subtotal'], 4), round($r['discount'], 4), round($r['tax'], 4), round($r['total'], 4)];
            }
            $rows[] = ['Totals', $data['totals']['txn_count'], round($data['totals']['subtotal'], 4), round($data['totals']['discount'], 4), round($data['totals']['tax'], 4), round($data['totals']['total'], 4)];
            return $rows;
        });
    }

    public function salesByProduct(Request $request): View|Response
    {
        [$from, $to] = $this->range($request);
        $data = $this->reports->salesByProduct($from, $to);
        $payload = array_merge($data, ['from' => $from, 'to' => $to]);

        return $this->respond($request, 'reports.sales-by-product', $payload, 'Sales by Product', function () use ($data) {
            $rows = [['Product', 'Units', 'Revenue', 'COGS', 'Profit', 'Margin %']];
            foreach ($data['rows'] as $r) {
                $rows[] = [$r['product_name'], (float) $r['units'], (float) $r['revenue'], (float) $r['cogs'], $r['profit'], $r['margin_pct']];
            }
            return $rows;
        });
    }

    public function stockLevels(Request $request): View|Response
    {
        $statusFilter = $request->query('status_filter');
        $data = $this->reports->stockLevels($statusFilter);
        $payload = array_merge($data, ['status_filter' => $statusFilter]);

        return $this->respond($request, 'reports.stock-levels', $payload, 'Inventory Stock Levels', function () use ($data) {
            $rows = [['Product', 'SKU', 'Stock', 'Min', 'Unit', 'Cost', 'Retail', 'Cost Value', 'Retail Value', 'Status']];
            foreach ($data['rows'] as $r) {
                $rows[] = [$r['name'], $r['sku'], (float) $r['stock_qty'], (float) $r['min_stock'], $r['unit'], (float) $r['cost_usd'], (float) $r['price_usd'], $r['stock_value_cost'], $r['stock_value_retail'], $r['status']];
            }
            $rows[] = ['Totals', '', '', '', '', '', '', $data['totals']['value_cost'], $data['totals']['value_retail'], ''];
            return $rows;
        });
    }

    public function profitLoss(Request $request): View|Response
    {
        [$from, $to] = $this->range($request);
        $data = $this->reports->profitLoss($from, $to);
        $payload = array_merge($data, ['from' => $from, 'to' => $to]);

        return $this->respond($request, 'reports.pnl', $payload, 'Profit & Loss', function () use ($data) {
            return [
                ['Line', 'Amount (USD)'],
                ['Gross Revenue', $data['gross_revenue']],
                ['Discounts', -$data['discounts']],
                ['Tax Collected', $data['tax_collected']],
                ['Net Revenue', $data['net_revenue']],
                ['COGS', -$data['cogs']],
                ['Gross Profit', $data['gross_profit']],
                ['Margin %', $data['margin_pct']],
                ['Transactions', $data['txn_count']],
            ];
        });
    }

    private function range(Request $request): array
    {
        $from = $request->query('from') ? Carbon::parse($request->query('from'))->startOfDay() : now()->subDays(30)->startOfDay();
        $to = $request->query('to') ? Carbon::parse($request->query('to'))->endOfDay() : now()->endOfDay();
        return [$from, $to];
    }

    private function respond(Request $request, string $view, array $payload, string $title, \Closure $exportRows): View|Response
    {
        $format = $request->query('format');

        if ($format === 'pdf') {
            $pdf = Pdf::loadView($view . '-pdf', $payload + ['title' => $title]);
            return $pdf->download(\Illuminate\Support\Str::slug($title) . '-' . now()->format('Ymd-His') . '.pdf');
        }

        if ($format === 'xlsx') {
            $rows = $exportRows();
            $export = new class($rows) implements FromArray, WithHeadings {
                public function __construct(private readonly array $rows) {}
                public function array(): array { return array_slice($this->rows, 1); }
                public function headings(): array { return $this->rows[0]; }
            };
            return Excel::download($export, \Illuminate\Support\Str::slug($title) . '-' . now()->format('Ymd-His') . '.xlsx');
        }

        return view($view, $payload + ['title' => $title]);
    }
}
