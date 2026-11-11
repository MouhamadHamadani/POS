@extends('reports._pdf-layout', [
    'meta' => __('debts.pdf_as_of', ['time' => now()->format('Y-m-d H:i')]),
])

@section('pdf-body')
    @php
        $money = fn ($v) => '$'.number_format((float) $v, 2);
        $cols = [
            ['label' => __('debts.cols.customer'), 'num' => false],
            ['label' => __('debts.cols.outstanding'), 'num' => true],
            ['label' => __('debts.cols.overdue'), 'num' => true],
            ['label' => __('debts.cols.oldest_due'), 'num' => true],
            ['label' => __('debts.cols.not_due'), 'num' => true],
            ['label' => __('debts.cols.days_1_30'), 'num' => true],
            ['label' => __('debts.cols.days_31_60'), 'num' => true],
            ['label' => __('debts.cols.days_61_90'), 'num' => true],
            ['label' => __('debts.cols.days_90'), 'num' => true],
        ];
        $rows = array_map(fn ($r) => [
            $r['customer']->name, $money($r['outstanding']), $money($r['overdue']), $r['oldest_due']?->format('Y-m-d') ?? '-',
            ...array_map(fn ($b) => $money($r['aging'][$b]), \App\Services\CustomerLedgerService::BUCKETS),
        ], $customers);
        $foot = [
            __('reports.cols.totals'), $money($totals['outstanding']), $money($totals['overdue']), '',
            ...array_map(fn ($b) => $money($totals[$b]), \App\Services\CustomerLedgerService::BUCKETS),
        ];
    @endphp
    @include('reports._pdf-table', ['cols' => $cols, 'data' => $rows, 'foot' => $foot, 'empty' => __('debts.none')])
@endsection
