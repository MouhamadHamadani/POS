<!doctype html>
<html>
<head>
<meta charset="utf-8"><title>{{ $title }}</title>
<style>body { font-family: DejaVu Sans, sans-serif; font-size: 11px; }
h1 { font-size: 16px; color: #1B3A6B; margin: 0 0 4px; }
.meta { color: #555; font-size: 10px; margin-bottom: 12px; }
table { width: 100%; border-collapse: collapse; }
th { background: #1B3A6B; color: white; padding: 6px 8px; font-size: 10px; text-align: left; }
td { padding: 6px 8px; border-bottom: 1px solid #eee; }
.right { text-align: right; }</style>
</head>
<body>
<h1>{{ $title }}</h1>
<div class="meta">Generated {{ now()->format('Y-m-d H:i') }} · Period {{ $from->format('Y-m-d') }} to {{ $to->format('Y-m-d') }}</div>
<table>
<thead><tr><th>Product</th><th class="right">Units</th><th class="right">Revenue</th><th class="right">COGS</th><th class="right">Profit</th><th class="right">Margin %</th></tr></thead>
<tbody>
@forelse ($rows as $r)
<tr>
    <td>{{ $r['product_name'] }}</td>
    <td class="right">{{ rtrim(rtrim(number_format((float) $r['units'], 4, '.', ''), '0'), '.') }}</td>
    <td class="right">${{ number_format((float) $r['revenue'], 2) }}</td>
    <td class="right">${{ number_format((float) $r['cogs'], 2) }}</td>
    <td class="right">${{ number_format((float) $r['profit'], 2) }}</td>
    <td class="right">{{ number_format($r['margin_pct'], 1) }}%</td>
</tr>
@empty
<tr><td colspan="6" style="text-align:center; padding:20px;">No sales in this period.</td></tr>
@endforelse
</tbody>
</table>
</body></html>
