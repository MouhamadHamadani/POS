@extends('reports._pdf-layout', [
    'maxWidth' => 500,
    'meta' => __('reports.pdf.generated_period', ['time' => now()->format('Y-m-d H:i'), 'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')]),
])

@section('pdf-body')
    @php
        $rtl = app()->getLocale() === 'ar';
        $money = fn ($v) => ((float) $v < 0 ? '-' : '').'$'.number_format(abs((float) $v), 2);
        // [label, amount, row class, label class]
        $lines = [
            [__('reports.pnl.gross_revenue'), $money($gross_revenue), '', ''],
            ['– '.__('reports.pnl.discounts'), $money($discounts), '', 'indent'],
            ['+ '.__('reports.pnl.tax_collected'), $money($tax_collected), '', 'indent'],
            [__('reports.pnl.net_revenue'), $money($net_revenue), 'heavy', ''],
            ['– '.__('reports.pnl.cogs'), $money($cogs), '', 'indent'],
            [__('reports.pnl.gross_profit'), $money($gross_profit), 'heavy', 'big'],
            [__('reports.pnl.margin'), number_format($margin_pct, 1).'%', '', ''],
            ['– '.__('reports.pnl.fixed_expenses'), $money($fixed_expenses), '', 'indent'],
            ['– '.__('reports.pnl.running_expenses'), $money($running_expenses), '', 'indent'],
            [__('reports.pnl.net_profit'), $money($net_profit), 'heavy', 'big'],
            [__('reports.pnl.net_margin'), number_format($net_margin_pct, 1).'%', '', ''],
            [__('reports.cols.transactions'), $txn_count, '', ''],
        ];
    @endphp
    <table>
        @foreach ($lines as [$label, $amount, $rowClass, $labelClass])
            @php $cells = [[$label, $labelClass, false], [$amount, $labelClass === 'big' ? 'big' : '', true]]; if ($rtl) { $cells = array_reverse($cells); } @endphp
            <tr class="{{ $rowClass }}">
                @foreach ($cells as [$text, $class, $isAmount])
                    <td class="{{ $class }} {{ $isAmount ? 'num' : '' }}">@pdf($text)</td>
                @endforeach
            </tr>
        @endforeach
    </table>
@endsection
