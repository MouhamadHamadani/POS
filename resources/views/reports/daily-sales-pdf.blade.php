<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>{{ $title }}</title>
<style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #222; }
    h1 { font-size: 16px; color: #1B3A6B; margin: 0 0 4px; }
    .meta { color: #555; font-size: 10px; margin-bottom: 12px; }
    table { width: 100%; border-collapse: collapse; }
    th { background: #1B3A6B; color: white; text-align: left; padding: 6px 8px; font-size: 10px; text-transform: uppercase; }
    td { padding: 6px 8px; border-bottom: 1px solid #eee; }
    tfoot td { background: #f5f7fa; font-weight: bold; border-top: 2px solid #1B3A6B; }
    .right { text-align: right; }
</style>
</head>
<body>
    <h1>{{ $title }}</h1>
    <div class="meta">Generated {{ now()->format('Y-m-d H:i') }} · Period {{ $from->format('Y-m-d') }} to {{ $to->format('Y-m-d') }}</div>

    <table>
        <thead>
            <tr><th>Day</th><th class="right">Transactions</th><th class="right">Subtotal</th><th class="right">Discount</th><th class="right">Tax</th><th class="right">Total</th></tr>
        </thead>
        <tbody>
        @forelse ($rows as $r)
            <tr>
                <td>{{ $r['day'] }}</td>
                <td class="right">{{ $r['txn_count'] }}</td>
                <td class="right">${{ number_format((float) $r['subtotal'], 2) }}</td>
                <td class="right">${{ number_format((float) $r['discount'], 2) }}</td>
                <td class="right">${{ number_format((float) $r['tax'], 2) }}</td>
                <td class="right">${{ number_format((float) $r['total'], 2) }}</td>
            </tr>
        @empty
            <tr><td colspan="6" style="text-align:center; padding: 20px;">No sales in this period.</td></tr>
        @endforelse
        </tbody>
        <tfoot>
            <tr>
                <td>Totals</td>
                <td class="right">{{ $totals['txn_count'] }}</td>
                <td class="right">${{ number_format((float) $totals['subtotal'], 2) }}</td>
                <td class="right">${{ number_format((float) $totals['discount'], 2) }}</td>
                <td class="right">${{ number_format((float) $totals['tax'], 2) }}</td>
                <td class="right">${{ number_format((float) $totals['total'], 2) }}</td>
            </tr>
        </tfoot>
    </table>
</body>
</html>
