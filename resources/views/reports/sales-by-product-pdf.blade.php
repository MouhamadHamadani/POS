@extends('reports._pdf-layout', [
    'meta' => __('reports.pdf.generated_period', ['time' => now()->format('Y-m-d H:i'), 'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')]),
])

@section('pdf-body')
    @php
        $money = fn ($v) => '$'.number_format((float) $v, 2);
        $cols = [
            ['label' => __('dashboard.product'), 'num' => false],
            ['label' => __('reports.cols.units'), 'num' => true],
            ['label' => __('reports.cols.revenue_ex_vat'), 'num' => true],
            ['label' => __('reports.cols.cogs'), 'num' => true],
            ['label' => __('reports.cols.profit'), 'num' => true],
            ['label' => __('reports.cols.margin_pct'), 'num' => true],
        ];
        $data = array_map(fn ($r) => [
            $r['local_name'] ?? $r['product_name'],
            rtrim(rtrim(number_format((float) $r['units'], 4, '.', ''), '0'), '.'),
            $money($r['revenue']), $money($r['cogs']), $money($r['profit']),
            number_format($r['margin_pct'], 1).'%',
        ], $rows);
    @endphp
    @include('reports._pdf-table', ['cols' => $cols, 'data' => $data, 'empty' => __('reports.pdf.no_sales_period')])
@endsection
