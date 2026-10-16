@extends('reports._pdf-layout', [
    'meta' => __('reports.pdf.generated_period', ['time' => now()->format('Y-m-d H:i'), 'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')]),
])

@section('pdf-body')
    @php
        $money = fn ($v) => '$'.number_format((float) $v, 2);
        $kind = fn ($k) => \App\Support\EnumLabel::get('expense_kind', $k);

        $cols = [
            ['label' => __('reports.cols.category'), 'num' => false],
            ['label' => __('expenses.cols.kind'), 'num' => false],
            ['label' => __('reports.cols.amount_usd'), 'num' => true],
            ['label' => __('reports.cols.share_pct'), 'num' => true],
        ];
        $data = array_map(fn ($c) => [$c['local_name'], $kind($c['kind']), $money($c['total']), number_format($c['pct'], 1).'%'], $categories);
        $data[] = [$kind('fixed'), '', $money($fixed), ''];
        $data[] = [$kind('running'), '', $money($running), ''];
        $foot = [__('reports.cols.totals'), '', $money($total), ''];
    @endphp
    @include('reports._pdf-table', ['cols' => $cols, 'data' => $data, 'foot' => $foot, 'empty' => __('expenses.empty')])

    @if (count($months))
        @php
            $mCols = [['label' => __('reports.cols.category'), 'num' => false]];
            foreach ($months as $m) { $mCols[] = ['label' => $m, 'num' => true]; }
            $mData = array_map(fn ($c) => array_merge([$c['local_name']], array_map(fn ($m) => $money($monthly[$c['id']][$m] ?? 0), $months)), $categories);
            $mFoot = array_merge([__('reports.cols.totals')], array_map(fn ($m) => $money($month_totals[$m]), $months));
        @endphp
        <h1 style="margin-top: 18px; font-size: 13px">@pdf(__('expenses.by_month'))</h1>
        @include('reports._pdf-table', ['cols' => $mCols, 'data' => $mData, 'foot' => $mFoot, 'empty' => __('expenses.empty')])
    @endif
@endsection
