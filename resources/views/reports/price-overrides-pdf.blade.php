@extends('reports._pdf-layout', [
    'meta' => __('reports.pdf.generated_period', ['time' => now()->format('Y-m-d H:i'), 'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')]),
])

@section('pdf-body')
    @php
        $money = fn ($v) => '$'.number_format((float) $v, 2);
        $signed = fn ($v) => ($v < 0 ? '-' : '+').'$'.number_format(abs((float) $v), 2);
        $cols = [
            ['label' => __('reports.cols.date'), 'num' => false],
            ['label' => __('reports.cols.receipt'), 'num' => false],
            ['label' => __('reports.cols.product'), 'num' => false],
            ['label' => __('reports.cols.cashier'), 'num' => false],
            ['label' => __('reports.cols.approver'), 'num' => false],
            ['label' => __('reports.cols.units'), 'num' => true],
            ['label' => __('reports.cols.list_price'), 'num' => true],
            ['label' => __('reports.cols.charged_price'), 'num' => true],
            ['label' => __('reports.cols.difference'), 'num' => true],
            ['label' => __('reports.cols.reason'), 'num' => false],
        ];
        $data = array_map(fn ($r) => [
            $r['date'], $r['receipt'], $r['local_name'], $r['cashier'] ?? '—', $r['approver'] ?? '—',
            rtrim(rtrim(number_format($r['qty'], 4, '.', ''), '0'), '.'),
            $money($r['list_price']), $money($r['charged_price']), $signed($r['difference']), $r['reason'] ?? '—',
        ], $rows);
        $foot = [__('reports.cols.net_difference'), '', '', '', '', '', '', '', $signed($totals['net_difference']), ''];
    @endphp
    @include('reports._pdf-table', ['cols' => $cols, 'data' => $data, 'foot' => count($rows) ? $foot : null, 'empty' => __('reports.no_price_overrides')])
@endsection
