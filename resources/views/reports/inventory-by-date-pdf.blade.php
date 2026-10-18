@extends('reports._pdf-layout', [
    'meta' => $mode === 'movement'
        ? __('reports.pdf.generated_period', ['time' => now()->format('Y-m-d H:i'), 'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')])
        : __('reports.pdf.generated_on_date', ['time' => now()->format('Y-m-d H:i'), 'date' => $date->format('Y-m-d'), 'count' => count($rows)]),
])

@section('pdf-body')
    @php
        $money = fn ($v) => '$'.number_format((float) $v, 2);
        $qty = fn ($v) => rtrim(rtrim(number_format((float) $v, 4, '.', ''), '0'), '.') ?: '0';
        $unit = fn ($r) => \App\Support\EnumLabel::get('unit', $r['unit']);

        if ($mode === 'movement') {
            $cols = [
                ['label' => __('dashboard.product'), 'num' => false],
                ['label' => 'SKU', 'num' => false],
                ['label' => __('reports.cols.opening_qty'), 'num' => true],
                ['label' => __('reports.cols.total_in'), 'num' => true],
                ['label' => __('reports.cols.total_out'), 'num' => true],
                ['label' => __('reports.cols.closing_qty'), 'num' => true],
            ];
            $data = array_map(fn ($r) => [
                $r['local_name'], $r['sku'], $qty($r['opening']), $qty($r['qty_in']), $qty($r['qty_out']), $qty($r['closing']).' '.$unit($r),
            ], $rows);
            $foot = [__('reports.cols.totals'), '', $qty($totals['opening']), $qty($totals['qty_in']), $qty($totals['qty_out']), $qty($totals['closing'])];
        } else {
            $cols = [
                ['label' => __('dashboard.product'), 'num' => false],
                ['label' => 'SKU', 'num' => false],
                ['label' => __('reports.cols.qty_on_date'), 'num' => true],
                ['label' => __('reports.cols.cost_value'), 'num' => true],
                ['label' => __('reports.cols.retail_value'), 'num' => true],
            ];
            $data = array_map(fn ($r) => [
                $r['local_name'], $r['sku'], $qty($r['qty']).' '.$unit($r), $money($r['value_cost']), $money($r['value_retail']),
            ], $rows);
            $foot = [__('reports.cols.totals'), '', '', $money($totals['value_cost']), $money($totals['value_retail'])];
        }
    @endphp
    @include('reports._pdf-table', ['cols' => $cols, 'data' => $data, 'foot' => $foot, 'empty' => __('reports.no_products_match')])
    @if ($mode !== 'movement')
        <p class="meta" style="margin-top: 8px">@pdf(__('reports.inv.note_cost'))</p>
    @endif
    <p class="meta">@pdf(__('reports.inv.note_history'))</p>
@endsection
