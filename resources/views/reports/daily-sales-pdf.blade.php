@extends('reports._pdf-layout', [
    'meta' => __('reports.pdf.generated_period', ['time' => now()->format('Y-m-d H:i'), 'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')]),
])

@section('pdf-body')
    @php
        $money = fn ($v) => '$'.number_format((float) $v, 2);
        $cols = [
            ['label' => __('reports.cols.day'), 'num' => false],
            ['label' => __('reports.cols.transactions'), 'num' => true],
            ['label' => __('reports.cols.subtotal'), 'num' => true],
            ['label' => __('reports.cols.discount'), 'num' => true],
            ['label' => __('reports.cols.tax'), 'num' => true],
            ['label' => __('reports.cols.total'), 'num' => true],
        ];
        $data = array_map(fn ($r) => [$r['day'], $r['txn_count'], $money($r['subtotal']), $money($r['discount']), $money($r['tax']), $money($r['total'])], $rows);
        $foot = [__('reports.cols.totals'), $totals['txn_count'], $money($totals['subtotal']), $money($totals['discount']), $money($totals['tax']), $money($totals['total'])];
    @endphp
    @include('reports._pdf-table', ['cols' => $cols, 'data' => $data, 'foot' => $foot, 'empty' => __('reports.pdf.no_sales_period')])
@endsection
