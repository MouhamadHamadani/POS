<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
<meta charset="utf-8">
<title>Receipt {{ $sale->receipt_number }}</title>
<style>
    @page { size: {{ $width }}mm auto; margin: 0; }
    html, body { margin: 0; padding: 0; background: white; font-family: 'Courier New', monospace; }
    body { width: {{ $width }}mm; padding: 4mm; font-size: 11px; color: #111; }
    .center { text-align: center; }
    .right  { text-align: right; }
    .small  { font-size: 10px; }
    .bold   { font-weight: bold; }
    hr { border: none; border-top: 1px dashed #555; margin: 3mm 0; }
    table { width: 100%; border-collapse: collapse; font-size: 11px; }
    td { padding: 1mm 0; vertical-align: top; }
    .item-row td { padding-top: 0.5mm; padding-bottom: 0.5mm; }
    .totals td { padding: 0.5mm 0; }
    .grand { font-size: 13px; font-weight: bold; }
    .actions { padding: 10mm 0; text-align: center; }
    .actions button { padding: 8px 16px; font-size: 14px; cursor: pointer; }
    @media print { .actions { display: none; } }
</style>
</head>
<body>
    <div class="center bold">{{ $business['name'] }}</div>
    @if ($business['name_ar']) <div class="center" dir="rtl">{{ $business['name_ar'] }}</div> @endif
    @if ($business['address']) <div class="center small">{{ $business['address'] }}</div> @endif
    @if ($business['phone']) <div class="center small">{{ $business['phone'] }}</div> @endif
    @if ($business['tax_number']) <div class="center small">VAT #: {{ $business['tax_number'] }}</div> @endif
    @if ($header)<div class="center small">{{ $header }}</div>@endif

    <hr>
    <div class="small">
        <strong>Receipt:</strong> {{ $sale->receipt_number }}<br>
        <strong>Date:</strong> {{ $sale->created_at->format('Y-m-d H:i') }}<br>
        <strong>Cashier:</strong> {{ $sale->user?->name ?? '—' }}<br>
        @if ($sale->customer)
            <strong>Customer:</strong> {{ $sale->customer->name }}<br>
        @endif
    </div>
    <hr>

    <table>
        @foreach ($sale->items as $item)
        <tr class="item-row">
            <td colspan="2">{{ $item->product_name }}</td>
        </tr>
        <tr class="item-row small">
            <td>
                {{ rtrim(rtrim(number_format((float) $item->qty, 4, '.', ''), '0'), '.') }}
                × ${{ number_format((float) $item->unit_price_usd, 2) }}
                @if ($item->discount_amount_usd > 0)
                    <br>&nbsp;&nbsp;disc: -${{ number_format((float) $item->discount_amount_usd, 2) }}
                @endif
            </td>
            <td class="right">${{ number_format((float) $item->line_total_usd, 2) }}</td>
        </tr>
        @endforeach
    </table>

    <hr>
    <table class="totals">
        <tr><td>Subtotal</td><td class="right">${{ number_format((float) $sale->subtotal_usd, 2) }}</td></tr>
        @if ($sale->discount_amount_usd > 0)
            <tr><td>Discount</td><td class="right">-${{ number_format((float) $sale->discount_amount_usd, 2) }}</td></tr>
        @endif
        @if ($sale->tax_amount_usd > 0)
            <tr><td>VAT</td><td class="right">${{ number_format((float) $sale->tax_amount_usd, 2) }}</td></tr>
        @endif
        <tr class="grand"><td>TOTAL</td><td class="right">${{ number_format((float) $sale->total_usd, 2) }}</td></tr>
        @if ($sale->total_lbp)
            <tr class="small"><td>LBP equiv.</td><td class="right">{{ number_format((float) $sale->total_lbp) }}</td></tr>
        @endif
    </table>

    <hr>
    <table class="small">
        <tr><td>Payment</td><td class="right">{{ str_replace('_', ' ', $sale->payment_method) }}</td></tr>
        @if ($sale->amount_tendered_usd)
            <tr><td>Cash USD tendered</td><td class="right">${{ number_format((float) $sale->amount_tendered_usd, 2) }}</td></tr>
        @endif
        @if ($sale->amount_tendered_lbp)
            <tr><td>Cash LBP tendered</td><td class="right">{{ number_format((float) $sale->amount_tendered_lbp) }} LBP</td></tr>
        @endif
        @if ($sale->amount_card_usd > 0)
            <tr><td>Card</td><td class="right">${{ number_format((float) $sale->amount_card_usd, 2) }}</td></tr>
        @endif
        @if ($sale->amount_credit_usd > 0)
            <tr><td>On account</td><td class="right">${{ number_format((float) $sale->amount_credit_usd, 2) }}</td></tr>
        @endif
        @if ($sale->change_usd > 0)
            <tr class="bold"><td>Change USD</td><td class="right">${{ number_format((float) $sale->change_usd, 2) }}</td></tr>
        @endif
        @if ($sale->change_lbp > 0)
            <tr class="bold"><td>Change LBP</td><td class="right">{{ number_format((float) $sale->change_lbp) }}</td></tr>
        @endif
    </table>

    @if ($footer)
        <hr>
        <div class="center small">{{ $footer }}</div>
    @endif

    @if ($sale->loyalty_points_earned > 0)
        <div class="center small">+{{ $sale->loyalty_points_earned }} loyalty points earned</div>
    @endif

    <div class="center small" style="margin-top: 6mm;">Receipt # {{ $sale->receipt_number }}</div>

    <div class="actions">
        <button type="button" onclick="window.print()">Print</button>
        <button type="button" onclick="window.close()">Close</button>
    </div>

    @if ($autoPrint)
        <script>
            window.addEventListener('load', () => {
                setTimeout(() => {
                    window.print();
                    setTimeout(() => window.close(), 500);
                }, 250);
            });
        </script>
    @endif
</body>
</html>
