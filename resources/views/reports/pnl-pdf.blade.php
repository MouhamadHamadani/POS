<!doctype html>
<html>
<head>
<meta charset="utf-8"><title>{{ $title }}</title>
<style>body { font-family: DejaVu Sans, sans-serif; font-size: 11px; }
h1 { font-size: 16px; color: #1B3A6B; margin: 0 0 4px; }
.meta { color: #555; font-size: 10px; margin-bottom: 16px; }
table { width: 100%; border-collapse: collapse; max-width: 500px; }
td { padding: 8px 12px; border-bottom: 1px solid #eee; }
.right { text-align: right; }
.indent { padding-left: 24px; color: #555; }
.heavy { font-weight: bold; background: #f5f7fa; border-top: 2px solid #1B3A6B; }
.total { font-weight: bold; font-size: 14px; color: #1B3A6B; }</style>
</head>
<body>
<h1>{{ $title }}</h1>
<div class="meta">Generated {{ now()->format('Y-m-d H:i') }} · Period {{ $from->format('Y-m-d') }} to {{ $to->format('Y-m-d') }}</div>

<table>
<tr><td>Gross Revenue</td><td class="right">${{ number_format($gross_revenue, 2) }}</td></tr>
<tr><td class="indent">– Discounts</td><td class="right">${{ number_format($discounts, 2) }}</td></tr>
<tr><td class="indent">+ Tax Collected</td><td class="right">${{ number_format($tax_collected, 2) }}</td></tr>
<tr class="heavy"><td>Net Revenue</td><td class="right">${{ number_format($net_revenue, 2) }}</td></tr>
<tr><td class="indent">– COGS</td><td class="right">${{ number_format($cogs, 2) }}</td></tr>
<tr class="heavy"><td class="total">Gross Profit</td><td class="right total">${{ number_format($gross_profit, 2) }}</td></tr>
<tr><td>Margin</td><td class="right">{{ number_format($margin_pct, 1) }}%</td></tr>
<tr><td>Transactions</td><td class="right">{{ $txn_count }}</td></tr>
</table>
</body></html>
