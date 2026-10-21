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

    {{-- The same breakdowns as the screen. Every string goes through @pdf() (inside the table partial), and
         the columns reverse for Arabic there too. --}}
    @php
        $pct = fn ($v) => number_format((float) $v, 1).'%';
        $breakdownCols = fn (string $first) => [
            ['label' => $first, 'num' => false],
            ['label' => __('reports.cols.transactions'), 'num' => true],
            ['label' => __('reports.cols.amount_usd'), 'num' => true],
            ['label' => __('reports.cols.share_pct'), 'num' => true],
        ];
    @endphp
    <h2>@pdf(__('reports.by_payment'))</h2>
    @include('reports._pdf-table', [
        'cols' => $breakdownCols(__('reports.cols.method')),
        'data' => array_map(fn ($p) => [\App\Support\EnumLabel::get('payment_method', $p['method']), $p['sales'], $money($p['usd']), $pct($p['share'])], $byPayment),
        'foot' => [__('reports.cols.totals'), '', $money(array_sum(array_column($byPayment, 'usd'))), ''],
        'empty' => __('reports.pdf.no_sales_period'),
    ])

    @if ($byChannel !== null)
        <h2>@pdf(__('reports.by_channel'))</h2>
        @include('reports._pdf-table', [
            'cols' => $breakdownCols(__('reports.cols.channel')),
            'data' => array_map(fn ($c) => [\App\Support\EnumLabel::get('sale_channel', $c['channel']), $c['sales'], $money($c['usd']), $pct($c['share'])], $byChannel),
            'empty' => __('reports.pdf.no_sales_period'),
        ])
    @endif
@endsection
