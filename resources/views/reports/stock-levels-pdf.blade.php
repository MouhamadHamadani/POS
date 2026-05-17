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
tfoot td { background: #f5f7fa; font-weight: bold; }
.right { text-align: right; }</style>
</head>
<body>
<h1>{{ $title }}</h1>
<div class="meta">Generated {{ now()->format('Y-m-d H:i') }} · {{ count($rows) }} products</div>
<table>
<thead><tr><th>Product</th><th>SKU</th><th class="right">Stock</th><th class="right">Min</th><th class="right">Cost Value</th><th class="right">Retail Value</th><th class="right">Status</th></tr></thead>
<tbody>
@foreach ($rows as $r)
<tr>
    <td>{{ $r['name'] }}</td>
    <td>{{ $r['sku'] }}</td>
    <td class="right">{{ rtrim(rtrim(number_format((float) $r['stock_qty'], 4, '.', ''), '0'), '.') }} {{ $r['unit'] }}</td>
    <td class="right">{{ rtrim(rtrim(number_format((float) $r['min_stock'], 4, '.', ''), '0'), '.') }}</td>
    <td class="right">${{ number_format((float) $r['stock_value_cost'], 2) }}</td>
    <td class="right">${{ number_format((float) $r['stock_value_retail'], 2) }}</td>
    <td class="right">{{ strtoupper($r['status']) }}</td>
</tr>
@endforeach
</tbody>
<tfoot>
<tr><td colspan="4">Totals</td><td class="right">${{ number_format((float) $totals['value_cost'], 2) }}</td><td class="right">${{ number_format((float) $totals['value_retail'], 2) }}</td><td></td></tr>
</tfoot>
</table>
</body></html>
