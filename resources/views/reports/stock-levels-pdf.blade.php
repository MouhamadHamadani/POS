@extends('reports._pdf-layout', [
    'meta' => __('reports.pdf.generated_products', ['time' => now()->format('Y-m-d H:i'), 'count' => count($rows)]),
])

@section('pdf-body')
    @php
        $money = fn ($v) => '$'.number_format((float) $v, 2);
        $qty = fn ($v) => rtrim(rtrim(number_format((float) $v, 4, '.', ''), '0'), '.');
        $cols = [
            ['label' => __('dashboard.product'), 'num' => false],
            ['label' => 'SKU', 'num' => false],
            ['label' => __('reports.cols.stock'), 'num' => true],
            ['label' => __('dashboard.min'), 'num' => true],
            ['label' => __('reports.cols.cost_value'), 'num' => true],
            ['label' => __('reports.cols.retail_value'), 'num' => true],
            ['label' => __('common.status'), 'num' => true],
        ];
        $data = array_map(fn ($r) => [
            $r['local_name'] ?? $r['name'],
            $r['sku'],
            $qty($r['stock_qty']).' '.\App\Support\EnumLabel::get('unit', $r['unit']),
            $qty($r['min_stock']),
            $money($r['stock_value_cost']), $money($r['stock_value_retail']),
            mb_strtoupper(__('enums.stock_status.'.$r['status'])),
        ], $rows);
        $foot = [__('reports.cols.totals'), '', '', '', $money($totals['value_cost']), $money($totals['value_retail']), ''];
    @endphp
    @include('reports._pdf-table', ['cols' => $cols, 'data' => $data, 'foot' => $foot, 'empty' => __('reports.no_products_match')])
@endsection
