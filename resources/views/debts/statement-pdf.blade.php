@extends('reports._pdf-layout', [
    'meta' => $customer->name.' · '.__('debts.statement_period').' '.$from->format('Y-m-d').' → '.$to->format('Y-m-d'),
])

@section('pdf-body')
    @php
        $money = fn ($v) => '$'.number_format((float) $v, 2);
        $cols = [
            ['label' => __('debts.cols.date'), 'num' => false],
            ['label' => __('debts.cols.entry'), 'num' => false],
            ['label' => __('debts.cols.debit'), 'num' => true],
            ['label' => __('debts.cols.credit'), 'num' => true],
            ['label' => __('debts.cols.balance'), 'num' => true],
        ];
        $rows = [[$from->format('Y-m-d'), __('debts.statement_opening'), '', '', $money($statement['opening'])]];
        foreach ($statement['rows'] as $r) {
            $rows[] = [
                $r['date']->format('Y-m-d'),
                trim($r['type_label'].' '.($r['receipt'] ?? $r['payment'] ?? '')),
                $r['debit'] ? $money($r['debit']) : '', $r['credit'] ? $money($r['credit']) : '', $money($r['balance']),
            ];
        }
        $foot = [__('debts.statement_balance_due'), '', $money($statement['debits']), $money($statement['credits']), $money($statement['closing'])];
    @endphp
    @include('reports._pdf-table', ['cols' => $cols, 'data' => $rows, 'foot' => $foot, 'empty' => ''])
@endsection
